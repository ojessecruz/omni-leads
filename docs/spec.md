# Sistema de Busca de Leads por ICP — Spec de Implementação

> Stack: Laravel, Livewire, Alpine.js, Tailwind CSS, PostgreSQL, Redis (Horizon)

---

## 1. Visão Geral

Pipeline de 4 estágios, com ICP como dado (não código):

```
ICP (config) → Descoberta (drivers) → Enriquecimento (waterfall) → Qualificação (score)
```

- **ICP aberto**: qualquer projeto define seu ICP via wizard (manual ou Ask AI).
- **Drivers plugáveis**: cada fonte de dados (Google Places, Apollo, CNPJ, Hunter...) implementa uma interface comum, estilo drivers de filesystem do Laravel.
- **Critérios em 2 categorias**:
  - `searchable` → viram query na fonte.
  - `filterable` → aplicados após enriquecimento (score/qualificação).

---

## 2. Modelagem de Dados

### 2.1 Tabelas

```
projects
  id, name, product_description (text), problem (text),
  current_customers (text), avg_ticket (string, nullable),
  timestamps

icps
  id, project_id (FK), name, status (enum: draft|active|archived),
  criteria (JSONB), timestamps

icp_drafts
  id, project_id (FK), user_id (FK), step (tinyint),
  mode (enum: manual|ask_ai), draft (JSONB), timestamps

search_jobs
  id, icp_id (FK), driver (string), status (enum: pending|running|done|failed),
  params (JSONB), results_count (int, default 0),
  started_at, finished_at, error (text nullable), timestamps

leads
  id, project_id (FK), icp_id (FK),
  name, company_name, domain (nullable, index),
  email (nullable, index), phone (nullable, index),
  whatsapp_valid (bool nullable), country (string 2),
  city (nullable), cnpj (nullable, index),
  score (unsigned tinyint, default 0),
  status (enum: new|qualified|discarded|contacted),
  timestamps
  UNIQUE: dedup via hash (ver 2.2)

lead_sources
  id, lead_id (FK), driver (string),
  external_id (string, index), raw_payload (JSONB), timestamps
  UNIQUE (driver, external_id)
```

### 2.2 Dedup

Coluna `dedup_hash` em `leads` (unique):

```php
$hash = sha1(strtolower($domain ?? $cnpj ?? $phone ?? $email));
```

Prioridade: `cnpj > domain > phone > email`. Se um novo lead colide, faz **merge**: adiciona `lead_source`, preenche campos nulos, recalcula score.

### 2.3 Formato do `criteria` (JSON)

```json
{
  "industry": "estética",
  "keywords": ["clínica de estética", "spa", "day spa"],
  "countries": ["BR", "US", "PT"],
  "regions": ["São Paulo", "Miami"],
  "company_size": "1-10",
  "job_titles": ["owner", "founder"],
  "signals": {
    "has_website": true,
    "min_reviews": 10,
    "min_rating": 4.0
  }
}
```

Validado por JSON Schema (ver §6.3). Campos desconhecidos são ignorados (forward-compatible).

---

## 3. Arquitetura de Drivers

### 3.1 Contratos

```php
interface LeadSourceDriver
{
    public function name(): string;                       // 'google_places'
    public function supports(IcpCriteria $criteria): bool; // ex.: CNPJ só BR
    public function mapper(): QueryMapper;
    /** @return Generator<LeadDTO> */
    public function search(array $queries): Generator;
}

interface QueryMapper
{
    /** Converte critérios genéricos em queries específicas da fonte */
    public function map(IcpCriteria $criteria): array;
    /** Critérios que a fonte NÃO suporta (viram filtro pós) */
    public function unsupported(IcpCriteria $criteria): array;
}
```

### 3.2 LeadDTO (normalização)

```php
readonly class LeadDTO
{
    public function __construct(
        public string $driver,
        public string $externalId,
        public ?string $name,
        public ?string $companyName,
        public ?string $domain,
        public ?string $email,
        public ?string $phone,
        public string $country,
        public ?string $city,
        public ?string $cnpj,
        public array $rawPayload,
    ) {}
}
```

### 3.3 Registro (Manager pattern)

```php
// LeadSourceManager extends Illuminate\Support\Manager
$manager->driver('google_places');
$manager->driver('apollo');
$manager->driver('cnpj_br');

// config/lead-sources.php
return [
    'drivers' => [
        'google_places' => ['key' => env('GOOGLE_PLACES_KEY'), 'enabled' => true],
        'apollo'        => ['key' => env('APOLLO_KEY'), 'enabled' => false],
        'cnpj_br'       => ['enabled' => true],
        'hunter'        => ['key' => env('HUNTER_KEY'), 'enabled' => true],
    ],
];
```

### 3.4 Mappers por driver

| Driver | searchable | estratégia |
|---|---|---|
| `google_places` | keywords, regions, countries | Grid de text queries: `"{keyword} in {city}"`. Contorna limite de 60 resultados quebrando por bairro/cidade. |
| `apollo` | industry, company_size, job_titles, countries | Mapeia direto pros filtros da API. |
| `cnpj_br` | industry (via de-para keyword→CNAE), regions | Tabela `cnae_mappings (keyword, cnae_code)`. Só roda se `countries` inclui BR. |

Critérios `unsupported()` de cada mapper são anotados no `search_job.params` e aplicados no estágio de qualificação.

---

## 4. Pipeline (Filas)

Todas as filas via Redis + Horizon. Filas nomeadas: `discovery`, `enrichment`, `scoring`.

### 4.1 Jobs

```
DispatchIcpSearchJob (icp_id)
  └─ para cada driver habilitado + compatível:
       cria search_job + dispatch SourceSearchJob

SourceSearchJob (search_job_id)          [queue: discovery]
  └─ mapper->map() → driver->search()
  └─ para cada LeadDTO: UpsertLeadJob (ou upsert inline em chunk)

EnrichLeadJob (lead_id)                  [queue: enrichment]
  ├─ domain sem email  → HunterLookup
  ├─ phone presente    → EvolutionApiWhatsAppCheck (whatsapp_valid)
  └─ dispatch ScoreLeadJob

ScoreLeadJob (lead_id)                   [queue: scoring]
  └─ aplica critérios filterable do ICP → score 0-100 + status
```

### 4.2 Rate limiting (por driver)

```php
// Em cada driver, via Redis::throttle ou RateLimited middleware
public function middleware(): array
{
    return [new RateLimited('google-places')]; // config em AppServiceProvider
}
```

Limites sugeridos: Places 10 req/s; Hunter 15 req/min (free); Evolution conforme sua instância.

### 4.3 Idempotência

- `SourceSearchJob` marca `search_job.status` e só processa se `pending`.
- Upsert por `(driver, external_id)` em `lead_sources` — reprocessar não duplica.

---

## 5. Enriquecimento (Waterfall)

Ordem por custo (grátis primeiro):

1. **Site do lead** (se veio do Places): scrape leve da home + `/contato` procurando `mailto:` e telefone. HTTP client com timeout 5s.
2. **Hunter.io** (domain-search): só se passo 1 falhou e há domínio. Consome créditos — gate por config `enrichment.hunter.enabled`.
3. **Evolution API**: `checkNumberStatus` para validar WhatsApp do telefone.

Cada passo grava resultado em `lead_sources` (driver `hunter`, `site_scrape`, etc.) para auditabilidade.

---

## 6. Wizard de ICP (Livewire)

### 6.1 Componente

`App\Livewire\IcpWizard` — único componente full-page com `$step`, `$mode`, `$draft` (array). Persiste em `icp_drafts` a cada `next()` (não perde no refresh).

```
Step 1: Contexto do projeto
  product_description, problem, current_customers, avg_ticket
  → salva direto em `projects` (reuso em ICPs futuros)

Step 2: Modo → 'manual' | 'ask_ai'

Step 3a (manual): Segmento & keywords
Step 4a (manual): Regiões & países (multi-select com busca)
Step 5a (manual): Tamanho, cargos & sinais

Step 3b (ask_ai): Prompt gerado + botão copiar (Alpine: navigator.clipboard)
Step 4b (ask_ai): Textarea "cole a resposta" → parse → validação

Step final: Revisão (sempre) — critérios editáveis → salva `icps` + dispatch opcional
```

### 6.2 Template do prompt (Ask AI)

Blade view `prompts/icp.blade.php`:

```
Você é um especialista em definição de ICP (Ideal Customer Profile).

Contexto do produto:
- Produto: {{ $project->product_description }}
- Problema que resolve: {{ $project->problem }}
- Clientes atuais: {{ $project->current_customers }}
- Ticket médio: {{ $project->avg_ticket ?? 'não informado' }}

Defina o ICP ideal e responda APENAS com um JSON válido neste formato,
sem texto antes ou depois:

{
  "industry": "string",
  "keywords": ["string"],
  "countries": ["ISO-3166 alpha-2"],
  "regions": ["string"],
  "company_size": "1-10 | 11-50 | 51-200 | 200+",
  "job_titles": ["string"],
  "signals": { "has_website": boolean, "min_reviews": number, "min_rating": number }
}
```

### 6.3 Parser/validador do retorno

```php
class IcpJsonParser
{
    public function parse(string $input): IcpCriteria
    {
        // 1. Strip markdown fences (```json ... ```)
        $input = preg_replace('/^```(?:json)?|```$/m', '', trim($input));
        // 2. Extrai primeiro bloco {...} se houver texto em volta
        // 3. json_decode + validação por JSON Schema (opis/json-schema)
        // 4. Normaliza: countries → uppercase, company_size → enum
        // Lança IcpParseException com mensagem amigável
    }
}
```

Erros comuns tratados: fences, texto antes/depois, vírgula sobrando, aspas simples.

---

## 7. Score de Qualificação

Score 0–100 somando pesos dos critérios `filterable` atendidos:

| Critério | Peso |
|---|---|
| País dentro do ICP | 20 |
| has_website atendido | 20 |
| min_reviews atendido | 15 |
| min_rating atendido | 15 |
| E-mail encontrado | 15 |
| WhatsApp válido | 15 |

- `score >= 60` → `status = qualified`
- Pesos em config (`config/lead-scoring.php`) para ajuste sem deploy.

---

## 8. UI (além do wizard)

- **Listagem de leads** (Livewire + tabela): filtros por ICP, status, score, país, origem. Ações em massa: descartar, marcar contatado, exportar CSV.
- **Detalhe do lead**: dados + accordion com raw payloads por fonte.
- **Dashboard do ICP**: leads por fonte, funil (descobertos → enriquecidos → qualificados), custo estimado de API.

---

## 9. Fases de Implementação

**Fase 1 — Fundação (MVP)**
- Migrations + models + `IcpCriteria` value object
- Driver `google_places` + mapper + grid de queries
- Pipeline discovery → upsert com dedup
- Wizard manual (sem Ask AI)
- Listagem simples de leads

**Fase 2 — Ask AI + Enriquecimento**
- Steps Ask AI (prompt + parser)
- Site scrape + Hunter + Evolution (WhatsApp)
- ScoreLeadJob + status

**Fase 3 — Escala BR + multi-fonte**
- Driver `cnpj_br` (importar dump da Receita, tabela `cnae_mappings`)
- Driver `apollo` (quando pagar valer a pena)
- Dashboard + export

**Fase 4 — Refinamento**
- Re-run periódico de ICPs (scheduler)
- Merge inteligente de leads duplicados entre ICPs
- Webhooks/integração com outbound (Evolution → sequências)

---

## 10. Decisões Registradas

1. **ICP como JSON validado por schema** — flexível, forward-compatible, sem migration por critério novo.
2. **searchable vs filterable** — fontes não precisam suportar todos os critérios.
3. **Ask AI é copy-paste, não API** — custo zero de tokens, usuário usa o agente que já paga.
4. **Dedup por hash prioritário** (cnpj > domain > phone > email) com merge.
5. **Raw payload sempre salvo** em `lead_sources` — auditoria e reprocessamento sem nova chamada de API.
6. **Places ToS**: dados não devem ser armazenados permanentemente além do `place_id` — risco assumido conscientemente ou mitigar re-consultando.
