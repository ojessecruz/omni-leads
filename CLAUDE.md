# Omni Leads

Busca de leads por ICP. Spec completa em `docs/spec.md` (fases em §9).

## Stack
Laravel 13 · Livewire 4 (class components) + Flux · Tailwind 4 · PostgreSQL 18 · Redis + Horizon · Pest 5.

## Rodar (Sail, portas não padrão)
```
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev       # Vite em :5190
./vendor/bin/sail artisan horizon   # filas: default, discovery, enrichment, scoring
```
- App: http://localhost:9030 · Horizon: /horizon · Mailpit: http://localhost:8040
- Postgres host: 54320 · Redis host: 6390 · SMTP Mailpit: 1040

Sempre rode `npm`/`composer`/`artisan` via `sail` — `node_modules` é do Linux arm64 do container, não do macOS.

## Organização do código
- `app/Domains/` = regra de negócio, PHP testável sem banco/fila: `Icp`, `LeadSources`, `Leads`, `Enrichment`, `Outbound`, `Webhooks`. Dentro de cada domínio, o serviço fica na raiz e os tipos em subpastas quando há mais de um: `Contracts/`, `Data/` (DTOs e value objects), `Exceptions/`, `Clients/` (HTTP), `Drivers/`.
- Fora de `Domains` é cola do Laravel: `Models`, `Jobs`, `Events`, `Listeners`, `Livewire`, `Http`, `Console`, `Policies`, `Support`. Livewire e Jobs são finos e só chamam o domínio.
- Ao criar um driver novo: `Domains/LeadSources/Drivers/<Nome>/` com `<Nome>Driver` + `<Nome>Mapper`, registrar `create<Nome>Driver()` no `LeadSourceManager` e a entrada em `config/lead-sources.php`.

## Arquitetura (Fase 1)
- `Domains/Icp/Data/IcpCriteria` — value object dos critérios (JSON em `icps.criteria`); campos desconhecidos são ignorados.
- `Domains/LeadSources/` — contratos `LeadSourceDriver` + `QueryMapper`, `LeadDTO`, `LeadSourceManager` (Manager pattern, `config/lead-sources.php`). Driver implementado: `GooglePlaces` (Places API New, text search, grid keyword×região).
- `Domains/Leads/LeadUpserter` — dedup por hash (cnpj > domain > phone > email) **por ICP**; colisão faz merge e adiciona `lead_sources`.
- Jobs: `DispatchIcpSearchJob` → `SourceSearchJob` (fila `discovery`, idempotente por `search_jobs.status`).
- Livewire: `Projects\Index|Show`, `Icps\Wizard` (6 passos, rascunho em `icp_drafts`), `Icps\Show`, `Leads\Index|Show`. Toda action que grava re-autoriza com `Gate::authorize` (não só o `mount`). Helpers internos de componente são `protected`; o que a view precisa vai por `render()`.
- Ações em lote nunca fazem fan-out no request: `Icps\Show` enfileira `EnrichIcpLeadsJob`/`RescoreIcpLeadsJob` (chunkById); "selecionar tudo" em `Leads\Index` marca só a página atual (`Index::PER_PAGE`).
- Validação em pt_BR: `lang/pt_BR/validation.php` (só as regras usadas).

## Fase 2
- Ask AI: `resources/views/prompts/icp.blade.php` (prompt) + `Domains/Icp/IcpJsonParser` (fences, texto em volta, vírgula sobrando, aspas simples, chaves sem aspas; schema em `resources/schemas/icp.schema.json` via opis/json-schema). No Ask AI o passo 5 é "Onde" (mesmo partial `icps/partials/where` do passo 4 manual) para ajustar o local sugerido; re-validar a resposta mantém as áreas já desenhadas.
- Enriquecimento (`Domains/Enrichment/`, `config/enrichment.php`): `LeadEnricher` roda waterfall site_scrape → hunter → evolution; cada passo grava `lead_sources` (driver `site_scrape|hunter|evolution`, `external_id` = `driver:lead_id`). `EnrichLeadJob` (fila `enrichment`) respeita `enriched_at` + TTL e sempre dispara `ScoreLeadJob`; usa middleware `RateLimited('enrichment')` (limiter no `AppServiceProvider` = menor RPM entre hunter/evolution habilitados) com `retryUntil` 6h e `maxExceptions` 2. O throttle com `usleep` dentro dos clientes fica só como rede de segurança.
- Score (`Domains/Leads/LeadScorer`, `config/lead-scoring.php`): normalizado sobre os pesos **aplicáveis** ao ICP; `qualified_threshold` move new ↔ qualified, nunca mexe em discarded/contacted. Breakdown aparece no detalhe do lead.

## Busca por raio (áreas no mapa)
- `IcpCriteria::$areas` = lista de `Domains/Icp/Data/GeoArea` (`lat`, `lng`, `radius_km` 0,5–50, `label`); `hasLocalities()` = regiões ou áreas.
- Google Places: cada keyword × área é uma busca só com a keyword + `locationRestriction` (a Text Search só aceita retângulo, então vai o `boundingBox()` do círculo) e o driver descarta o que cair fora do raio por haversine (`places.location` no field mask).
- Apollo usa o `label` da área como localidade (sem raio, `areas` em `unsupported`). `cnpj_br` não tem coordenadas: não roda quando o ICP só tem áreas.
- UI: `icps/partials/area-map` + `resources/js/area-map.js` (Alpine `areaMap`, Leaflet importado sob demanda; tiles em `config(services.map_tiles)`, padrão OpenStreetMap sem chave escurecido por CSS `.area-map-dark`). Busca de endereço via `Domains/Icp/Clients/PlaceLookupClient` (Places Text Search só com campos de localização), limitada a `Wizard::PLACE_LOOKUPS_PER_MINUTE` por usuário.

## Qualidade
```
./vendor/bin/sail artisan test --compact
./vendor/bin/sail pint --dirty
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G
```

## Fase 3
- Export CSV na listagem (`Domains/Leads/LeadCsvExporter`, `;` + BOM, respeita filtros e seleção).
- `Domains/Leads/IcpStats`: leads por fonte e custo estimado (`requests_count` em `search_jobs` via `TracksRequests` + `lead_sources` de hunter/evolution × `cost_per_request` das configs).
- Driver `apollo` (`Domains/LeadSources/Drivers/Apollo`): People Search, uma query com todos os filtros; exige cargo + keyword; e-mail bloqueado é descartado.
- Driver `cnpj_br` (`Domains/LeadSources/Drivers/CnpjBr`): base local da Receita. Importar com `sail artisan cnpj:import {cnaes|municipios|estabelecimentos|empresas} arquivo.csv` (nessa ordem; só ativos por padrão). De-para keyword→CNAE em `cnae_mappings` (seeder `CnaeMappingSeeder`) com fallback por descrição (`unaccent`). Só roda se o ICP inclui BR e a base tem dados.

## Fase 4
- Leads ↔ ICPs é N:N (`icp_lead`); `leads.icp_id` é só o ICP de origem. Dedup é **por projeto** (`unique(project_id, dedup_hash)`) e o `LeadUpserter` também casa por qualquer chave forte (cnpj/domain/phone/email). `Lead::inIcp($id)` para filtrar; `$icp->leads()` é belongsToMany (cuidado com selectRaw + pivô, use `Lead::inIcp`).
- `Domains/Leads/LeadMerger` + `MergeDuplicateLeadsJob` (único por projeto, roda após cada busca e às 03:00 via `leads:merge-duplicates`). Mantém o lead mais antigo, status mais avançado (contacted > discarded > qualified > new), move fontes e une ICPs.
- Re-execução: `icps.rerun_every_days/next_run_at`; `icps:rerun-due` de hora em hora (`routes/console.php`). Precisa do scheduler rodando (`sail artisan schedule:work`).
- Eventos (`app/Events`) → `app/Listeners/DispatchWebhooks` (auto-discovery, não registrar à mão) → `DeliverWebhookJob` (HMAC em `X-OmniLeads-Signature`; 5xx/conexão retentam 5x, 4xx é definitivo; cada entrega conta 1 falha, desativa após 20). Webhooks e template de WhatsApp em `/projects/{id}/settings`.
- Outbound: `SendWhatsAppJob` → `EvolutionClient::sendText`; `tries = 1` (POST sem idempotência), grava `lead_sources.whatsapp_outbound` (`external_id` = delivery id, message id no payload) **antes** de enviar e pula se já houver resposta; marca contatado.
- Filas: `REDIS_QUEUE_RETRY_AFTER` (1830) precisa ser maior que o maior `$timeout` de job (`SourceSearchJob` 1800), senão o Horizon re-reserva jobs em andamento. `Model::preventLazyLoading` está ligado fora de produção.

## i18n
- Comentários de código (PHP, Blade, CSS, JS, testes) sempre em inglês. Nomes de testes (`test('...')`) ficam em português.
- Padrão `en`; `pt_BR` e `es` em `lang/{locale}.json` (chaves = texto em inglês via `__()`/`trans_choice`). Validação em `lang/{en,pt_BR,es}/validation.php`.
- `SetLocale` middleware: `users.locale` > sessão (`Locales::SESSION_KEY`, escolha do visitante) > Accept-Language > `Locales::DEFAULT`. **Nunca** use `config('app.locale')` como padrão: `app()->setLocale()` sobrescreve.
- Switcher `<x-ui.language-switcher>` (LP, auth e header do app) posta em `locale.update`: logado grava em `users.locale` e limpa a sessão, visitante grava na sessão. É `<details>` + form, sem JS, porque a landing não carrega Alpine/Flux. Settings › Appearance continua como a preferência da conta.
- Mensagens do framework traduzidas em `lang/{locale}/{validation,auth,passwords}.php`.
- Países: `Countries::name()`/`options()` seguem o locale (`ALL` pt, `EN`, `ES`). Prompt do Ask AI: `prompts/icp-{locale}.blade.php`.
- Teste `LocaleTest` falha se alguma chave `__()` não tiver tradução em pt_BR/es — ao criar texto novo, adicione nos dois JSON.

## Design system (LP e app compartilham)
- **Dark-only.** Tokens em `resources/css/app.css`: escala `zinc` retunada (950 `#09090b` ink, 900 `#0e0e11` panel), `--color-signal` (#d7ff3c) como `--color-accent` do Flux (sobrescrito também em `@layer theme .dark`, porque `flux.css` força branco no dark). Não existe toggle de tema; `@fluxAppearance` foi removido do head.
- Fontes: Instrument Sans (texto), Instrument Serif itálico (`.serif-it`, só para o destaque do título), JetBrains Mono (`font-mono`, tudo que é dado/código/label; os feature settings vêm do tema). Tokens extras: `text-2xs` (11px), `tracking-label`/`tracking-eyebrow`, `shadow-glow`, `bg-panel`, `bg-ink`, `text-signal` — nunca use valores arbitrários (`text-[11px]`, `bg-[#0e0e11]`) para o que já tem token. Utilitários registrados com `@utility` (aceitam variantes e são sobrescritos por utilities comuns sem `!`): `.eyebrow` (mono 11px uppercase), `.display` (título), `.panel` + `.panel-head`, `.cell-grid` (grid com hairline; preencha células vazias com fillers), `.kv` (dl), `.chip`/`.chip-signal`, `.grid-bg`, `.glow`, `.noise`, `.tick`, `.pulse`.
- Flux é restilizado por CSS global **sem layer** (`[data-flux-*]`, como o próprio `flux.css` faz: dentro de `@layer` perderia para as utilities do Flux): botões pill, labels de campo em mono uppercase (labels de checkbox/radio/switch ficam em sans), colunas de tabela em mono, inputs com fundo panel. Use `background-color`, nunca `background`, ao sobrescrever `[data-flux-control]` (o chevron do select é background-image).
- Componentes de página: `<x-ui.page-header eyebrow|crumbs title accent description>` (accent = frase em serif itálico), `<x-ui.crumb>`, `<x-ui.panel eyebrow title :padding>` com slot `actions`, `<x-ui.kv>`, `<x-ui.stat>`, `<x-ui.empty code title>` com slot `actions`, `<x-brand>`.
- Shell: `layouts/app/header.blade.php` (top-nav fixo, sem sidebar; menu do usuário abre os links no mobile). Auth: `layouts/auth/split.blade.php` (painel esquerdo = mini hero). `body.app` faz `[data-reveal]` animar por CSS na inserção (sem IntersectionObserver, sobrevive a morph/`wire:poll`); na landing o reveal é por IO com `threshold`.
- Landing (`welcome.blade.php`) é standalone e só importa `app.css`. Motion: log simulado em JS, contador de score, `prefers-reduced-motion`. Grids responsivos precisam de `grid-cols-1` base. Textos via `__()` → adicionar em `lang/pt_BR.json` e `lang/es.json`.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `vendor/bin/sail artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `vendor/bin/sail artisan test --compact`.

</laravel-boost-guidelines>
