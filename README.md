<p align="center">
  <img src="public/images/omni-leads-logo.png" alt="Omni Leads" width="320">
</p>

<p align="center">
  Busca de leads por ICP. Descreva para quem você vende e o sistema transforma isso em buscas,
  enriquece cada empresa com e-mail e WhatsApp e pontua todas contra o seu perfil de cliente ideal.
</p>

<p align="center">
  <a href="https://github.com/ojessecruz/omni-leads/actions/workflows/ci.yml"><img src="https://github.com/ojessecruz/omni-leads/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

## Como funciona

Pipeline de quatro estágios, com o ICP como dado e não como código:

```
ICP (wizard ou Ask AI) → Descoberta (drivers) → Enriquecimento (waterfall) → Qualificação (score)
```

| Estágio | O que acontece |
| --- | --- |
| **ICP** | Um wizard de 6 passos define segmento, keywords, países, regiões, tamanho, cargos e sinais. No modo Ask AI você copia um prompt, cola no seu agente e traz o JSON de volta. |
| **Descoberta** | Cada fonte é um driver plugável. Hoje: Google Places (Text Search), Apollo.io (People Search) e a base pública da Receita Federal (`cnpj_br`, sem custo de API). |
| **Enriquecimento** | Do grátis para o pago: scrape do site → Hunter.io → validação de WhatsApp na Evolution API. Cada passo fica registrado para auditoria e custo. |
| **Qualificação** | Score 0–100 normalizado sobre os critérios aplicáveis ao ICP. Acima do limiar o lead vira *qualificado*. |

Depois disso: listagem com filtros e export CSV, dedup por projeto com merge automático, re-execução agendada das buscas, webhooks assinados (HMAC) por evento e primeira mensagem de WhatsApp direto da página do lead.

## Stack

Laravel 13 · Livewire 4 + Flux · Tailwind 4 · PostgreSQL 18 · Redis + Horizon · Pest 5 · Laravel Sail.

Interface dark-only, com um design system próprio compartilhado entre a landing e o app. Textos em `en`, `pt_BR` e `es`.

## Rodando localmente

Requer Docker. O projeto usa portas não padrão para não colidir com outros Sail na mesma máquina.

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev          # Vite em :5190
./vendor/bin/sail artisan horizon      # filas: default, discovery, enrichment, scoring
./vendor/bin/sail artisan schedule:work
```

| Serviço | Endereço |
| --- | --- |
| App | http://localhost:9030 |
| Horizon | http://localhost:9030/horizon |
| Mailpit | http://localhost:8040 |
| Postgres | `localhost:54320` |
| Redis | `localhost:6390` |

O seed carrega o de-para keyword → CNAE e cria o usuário `test@example.com` / `password`. Crie o primeiro projeto pela interface.

Sempre rode `npm`, `composer` e `artisan` através do `sail`: o `node_modules` é do Linux do container, não do macOS.

## Fontes e chaves

Tudo é ligado por `.env`. Só o Google Places vem habilitado por padrão.

| Fonte | Variáveis | Custo |
| --- | --- | --- |
| Google Places | `GOOGLE_PLACES_ENABLED`, `GOOGLE_PLACES_KEY` | ~US$ 35 / 1000 requisições (Enterprise SKU) |
| Apollo.io | `APOLLO_ENABLED`, `APOLLO_KEY` | créditos só no desbloqueio de e-mail |
| Receita Federal (`cnpj_br`) | `CNPJ_BR_ENABLED` + importação abaixo | grátis |
| Scrape do site | `SITE_SCRAPE_ENABLED` | grátis |
| Hunter.io | `HUNTER_ENABLED`, `HUNTER_KEY` | 1 crédito por domain search |
| Evolution API | `EVOLUTION_ENABLED`, `EVOLUTION_BASE_URL`, `EVOLUTION_INSTANCE`, `EVOLUTION_API_KEY` | conforme sua instância |

Para a base da Receita, baixe os CSVs em https://dadosabertos.rfb.gov.br/CNPJ/ e importe nesta ordem:

```bash
sail artisan cnpj:import cnaes Cnaes.csv
sail artisan cnpj:import municipios Municipios.csv
sail artisan cnpj:import estabelecimentos Estabelecimentos0.csv   # repita para cada parte
sail artisan cnpj:import empresas Empresas0.csv
```

## Qualidade

```bash
./vendor/bin/sail artisan test --compact
./vendor/bin/sail bin pint --dirty
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G
```

O CI roda os três em cada push e pull request.

## Organização do código

```
app/
  Domains/          regra de negócio, PHP testável sem banco nem fila
    Icp/            critérios (value object) e parser da resposta do Ask AI
    LeadSources/    contratos, LeadDTO, LeadSourceManager e Drivers/{GooglePlaces,Apollo,CnpjBr}
    Leads/          upsert com dedup, merge, score, stats e export CSV
    Enrichment/     waterfall e Clients/{SiteScraper,HunterClient,EvolutionClient}
    Outbound/       template de WhatsApp
    Webhooks/       payload assinado
  Jobs/ Events/ Listeners/ Livewire/ Models/ ...   cola do Laravel, fina, chama o domínio
```

Para adicionar uma fonte: crie `Domains/LeadSources/Drivers/<Nome>/` com `<Nome>Driver` e `<Nome>Mapper`, registre `create<Nome>Driver()` no `LeadSourceManager` e a entrada em `config/lead-sources.php`.

A spec completa está em [`docs/spec.md`](docs/spec.md). As convenções do projeto ficam em [`CLAUDE.md`](CLAUDE.md) e em [`.ai/rules`](.ai/rules), lidas por agentes de código via [Laravel Boost](https://github.com/laravel/boost).

## Webhooks

Cada entrega leva `X-OmniLeads-Event`, `X-OmniLeads-Delivery` e `X-OmniLeads-Signature: sha256=<hmac do corpo com o secret>`. Eventos: `lead.qualified`, `lead.contacted`, `lead.enriched`, `search.finished`, `webhook.test`. Erros 5xx e de conexão retentam com backoff; 4xx é falha definitiva. Após 20 entregas falhas o webhook é desativado.
