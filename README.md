<p align="center">
  <img src=".github/assets/readme-banner.png" alt="Omni Leads" width="720">
</p>

<p align="center">
  ICP-driven lead generation. Describe who you sell to and the system turns it into searches,
  enriches every company with email and WhatsApp, scores each one against your ideal customer
  profile and lets you talk to them from a built-in WhatsApp inbox.
</p>

<p align="center">
  <a href="https://github.com/ojessecruz/omni-leads/actions/workflows/ci.yml"><img src="https://github.com/ojessecruz/omni-leads/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

## How it works

A four-stage pipeline, with the ICP as data rather than code:

```
ICP (wizard or Ask AI) → Discovery (drivers) → Enrichment (waterfall) → Qualification (score)
```

| Stage | What happens |
| --- | --- |
| **ICP** | A 6-step wizard defines segment, keywords, countries, regions, company size, job titles and signals. In Ask AI mode you copy a prompt, paste it into your agent and bring the JSON back. |
| **Discovery** | Each source is a pluggable driver. Today: Google Places (Text Search, restricted to the region's boundaries and the ICP country), Apollo.io (People Search) and the Brazilian public company registry (`cnpj_br`, no API cost). |
| **Enrichment** | From free to paid: website scrape → Hunter.io → WhatsApp validation on the Evolution API. Every step is logged for auditing and cost tracking. |
| **Qualification** | A 0–100 score normalized over the criteria that apply to the ICP. Above the threshold the lead becomes *qualified*. |

After that:

- **Leads** — status tabs, searchable filter dropdowns, compact table with per-row actions, CSV export, per-project dedup with automatic merge and scheduled re-runs of searches.
- **Outreach** — first WhatsApp message straight from the lead list or lead page, and cold email through a per-project connector (Saleshandy).
- **WhatsApp inbox** — conversations with leads arrive in real time (Reverb WebSocket), with contact photos, unread counts, desktop notifications when the window is out of focus and read receipts sent only when a message is actually seen.
- **Safe first contacts** — messages to numbers that never replied are paced: hourly cap, a daily cap that grows while a newly paired phone warms up, business hours, and the queue pauses when the phone disconnects.
- **Webhooks and API** — signed webhooks per event (see below) and a token-based REST API to read and reply to conversations from your own bot.
- **Dashboard** — a first-steps checklist that guides new users through the setup.

## Stack

Laravel 13 · Livewire 4 + Flux · Tailwind 4 · PostgreSQL 18 · Redis + Horizon · Reverb · Sanctum · Pest · Laravel Sail.

Dark UI by default with an optional light theme (Settings › Appearance) and an in-house design system shared between the landing page and the app. UI available in `en`, `pt_BR` and `es`, including every validation message.

## Running locally

Requires Docker. The project uses non-default ports so it doesn't clash with other Sail projects on the same machine.

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev          # Vite on :5190
./vendor/bin/sail artisan horizon      # queues: default, discovery, enrichment, scoring
./vendor/bin/sail artisan reverb:start # WebSocket for the WhatsApp inbox
./vendor/bin/sail artisan schedule:work
```

| Service | Address |
| --- | --- |
| App | http://localhost:9030 |
| Horizon | http://localhost:9030/horizon |
| Reverb | `localhost:8080` |
| Mailpit | http://localhost:8040 |
| Postgres | `localhost:54320` |
| Redis | `localhost:6390` |

The seeder loads the keyword → CNAE mapping and creates the user `test@example.com` / `password`. Create your first project through the UI.

Always run `npm`, `composer` and `artisan` through `sail`: `node_modules` belongs to the container's Linux, not macOS.

### Useful environment flags

| Variable | Purpose |
| --- | --- |
| `REGISTRATION_ENABLED` | Public sign-up. When `false`, users join by email invitation only. |
| `PASSKEYS_ENABLED` | Passkey sign-in. |
| `HORIZON_ADMINS` | Comma-separated emails allowed into the Horizon dashboard. |
| `R2_*` | Cloudflare R2 storage for files (profile photos, WhatsApp media); everything goes under `R2_ROOT`. |
| `REVERB_*` | WebSocket server; `REVERB_PUBLIC_*` is what the browser connects to behind a proxy. |

## Sources and keys

API keys are BYOK: each user adds their own under **Settings › Integrations**. The key is verified against the provider before it's saved, stored encrypted and applies to all of the user's projects. A provider is active when the project owner has its key. Only Google Places is required, since searches can't run without it. Sources that need no key stay in `.env`.

| Source | Where to enable | Cost |
| --- | --- | --- |
| Google Places (required) | Integrations | ~US$ 35 / 1000 requests (Enterprise SKU) |
| Apollo.io | Integrations | credits only when unlocking emails |
| Brazilian company registry (`cnpj_br`) | `CNPJ_BR_ENABLED` + import below | free |
| Website scrape | `SITE_SCRAPE_ENABLED` | free |
| Hunter.io | Integrations | 1 credit per domain search |
| Evolution API (WhatsApp) | Integrations: the Omni Leads server (`EVOLUTION_BASE_URL` + `EVOLUTION_API_KEY` in `.env`, optional) or the user's own server; then "Connect phone" with the QR code | depends on your instance |
| Saleshandy (cold email) | Integrations; each project picks its connector in Settings | depends on your plan |

For the company registry, download the CSVs from https://dadosabertos.rfb.gov.br/CNPJ/ and import them in this order:

```bash
sail artisan cnpj:import cnaes Cnaes.csv
sail artisan cnpj:import municipios Municipios.csv
sail artisan cnpj:import estabelecimentos Estabelecimentos0.csv   # repeat for each part
sail artisan cnpj:import empresas Empresas0.csv
```

## Webhooks

Configured per project under **Project › Settings**. Each delivery carries `X-OmniLeads-Event`, `X-OmniLeads-Delivery` and `X-OmniLeads-Signature: sha256=<HMAC of the body with the secret>`.

| Event | When |
| --- | --- |
| `lead.qualified` | A lead crosses the score threshold |
| `lead.contacted` | A lead is marked as contacted |
| `lead.enriched` | Enrichment finished for a lead |
| `search.finished` | A search run finished |
| `whatsapp.message_received` | A lead sent a WhatsApp message (payload has the message, contact, conversation and lead) |
| `webhook.test` | Test button |

`whatsapp.message_received` goes to the projects of the conversation's leads, or to all of the user's projects when the number isn't linked to a lead yet.

Webhooks can also send **custom headers** (e.g. `Authorization: Bearer …`), one `Name: value` per line, up to 10. They're stored encrypted; `Content-Type`, `Host`, `User-Agent` and `X-OmniLeads-*` are reserved.

5xx and connection errors are retried with backoff; 4xx is a final failure. After 20 failed deliveries the webhook is deactivated.

## API

Token-based REST API (Laravel Sanctum). Create a token under **Settings › API** and pick its abilities; the token is shown only once.

```
Authorization: Bearer <token>
Accept: application/json
```

| Method | Endpoint | Ability | Description |
| --- | --- | --- | --- |
| `GET` | `/api/conversations/awaiting` | `conversations:read` | Conversations whose last message came from the contact, oldest waiting first. Paginated (`per_page` 1–100). |
| `GET` | `/api/conversations/{id}/messages` | `conversations:read` | Messages of a conversation, newest first, cursor-paginated. |
| `POST` | `/api/conversations/{id}/messages` | `conversations:reply` | Sends `{"text": "..."}` (max 2000 chars). Returns `202 {"status":"queued"}` and marks the conversation as read. |

Rate limit: 120 requests per minute. A typical bot loop: receive `whatsapp.message_received` → read the messages → reply.

## Quality

```bash
./vendor/bin/sail artisan test --compact
./vendor/bin/sail bin pint --dirty
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G
```

CI runs all three on every push and pull request.

## Code layout

```
app/
  Domains/          business rules, plain PHP testable without database or queues
    Icp/            criteria (value object) and the Ask AI response parser
    LeadSources/    contracts, LeadDTO, LeadSourceManager and Drivers/{GooglePlaces,Apollo,CnpjBr}
    Leads/          upsert with dedup, merge, score, stats and CSV export
    Enrichment/     waterfall and Clients/{SiteScraper,HunterClient,EvolutionClient}
    Integrations/   key verification and WhatsApp pairing
    Outbound/       WhatsApp and email templates, outbound connectors
    WhatsApp/       inbound webhook parser, inbox, phone numbers, first-contact pacing
    Onboarding/     first-steps checklist
    Webhooks/       signed payload and custom headers
  Actions/          use cases shared by Livewire and the API (e.g. reply to a conversation)
  Http/ Jobs/ Events/ Listeners/ Livewire/ Models/ ...   thin Laravel glue that calls the domain
```

To add a source: create `Domains/LeadSources/Drivers/<Name>/` with `<Name>Driver` and `<Name>Mapper`, register `create<Name>Driver()` in `LeadSourceManager` and add the entry to `config/lead-sources.php`.

The full spec is in [`docs/spec.md`](docs/spec.md). Project conventions live in [`CLAUDE.md`](CLAUDE.md) and [`.ai/rules`](.ai/rules), read by coding agents through [Laravel Boost](https://github.com/laravel/boost).
