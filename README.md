# Label Extraction Agent

Upload a product label or specification sheet; a vision LLM reads it and returns structured product
data — product name, brand, ingredients, allergens and net weight — which the app stores and renders
field by field, with the page each value came from.

Built for SupplyScope's Stage-1 developer trial.

> **Status: in active development.** The sections marked 🚧 below are filled in as the build
> progresses. See [Build status](#build-status) for what currently works.

---

## What it does

1. You sign in. There is no public access and no self-registration — see
   [Access control](#access-control).
2. You drop one or more files onto the upload page (PDF, JPEG, PNG or WebP).
3. Each file is validated, hashed, and written to object storage — then a job is queued. The request
   returns immediately; nothing waits on the model.
4. A **separate worker container** picks the job up, sends the document to OpenAI, validates what
   comes back, and persists it.
5. The list view updates as documents move `queued → processing → completed`, and failures surface a
   readable reason with a retry button.

Extraction takes roughly **18 seconds** for a 3-page specification sheet. That number is the entire
justification for the queue: it is far outside a reasonable web-request budget, so the work has to
happen out of band or the upload request holds a connection open for the duration.

## Why the design looks like this

The design is driven by what the sample documents actually turned out to be. They are not photos of
labels — they are 3-page **product specification sheets**, rendered as images at 1660×2340 px with
**zero embedded text**. Three consequences shaped everything:

**A vision model is mandatory.** Text extraction returns nothing at all, so there is no "parse the
PDF text" path to fall back on. The whole document goes to the model as a file.

**Fields span multiple pages.** Product name, brand and pack size sit on page 1; ingredients and
allergens on page 2. Any first-page-only implementation silently loses half the data and looks like
it worked.

**The documents are genuinely ambiguous, and the schema has to say so honestly.** This is the part
that matters most for a food-safety product:

| Ambiguity found in a sample | Naive result | What this app does |
|---|---|---|
| Allergen statement reads *"VITAL NOT COMPLETED"*, while Fish, Wheat and Milk appear only as bold words inside the ingredient declaration | Either "no allergens" (dangerously wrong) or an invented declared list | Records `statement_status: not_completed` **and** `derived_from_ingredients: [Fish, Wheat, Milk]` — the truthful answer |
| One page offers `Weight-Portion 112 g`, `NET Weight/Pack 800 g`, and `Pack Size 800 g × 4 bags/carton` | A bare `"800g"` that throws away what it refers to | `{ value, unit, basis: per_pack, raw_text, source_page }` |
| One sample is a cleaning chemical, not food — "ingredients" means something else and "allergens" barely applies | Empty strings, or fabricated allergens | `product_type: non_food`, allergens `not_applicable`, nullable fields with a stated reason |

Every extracted field carries the **page number it came from**, and the model is instructed never to
infer or invent — absent values come back `null` with a warning attached, not as a plausible guess.

## Architecture

One image, two roles. The same artifact runs the web process and the worker process; only the
command differs. That is what makes it deployable to any container host without a second build.

```
                        ┌──────────────────────────────────────┐
                        │  image: label-extractor (multi-stage)│
                        │  vendor + built JS baked in, no      │
                        │  composer/node in the final layer    │
                        └───────────┬──────────────┬───────────┘
                                    │              │
                     CMD: frankenphp│              │CMD: artisan queue:work
                                    ▼              ▼
Browser ──▶  ┌──────────────┐   ┌────────┐    ┌──────────┐
 (React 19   │   web        │   │  web   │    │  worker  │  ← scales independently
  + Inertia) │  container   │   │  (xN)  │    │   (xN)   │
             └──────┬───────┘   └────────┘    └────┬─────┘
                    │  POST /documents               │
                    │  (validate, hash, store,       │  re-reads file, calls OpenAI,
                    │   enqueue, return)             │  validates, persists
                    ▼                                ▼
             ┌──────────────┐  ┌──────────────┐  ┌─────────────────────────┐
             │  Redis       │  │ PostgreSQL 17│  │  Object storage         │
             │  queue+cache │  │              │  │  dev: shared volume     │
             │  +session    │  │              │  │  prod: S3/GCS           │
             └──────────────┘  └──────────────┘  └─────────────────────────┘
                    ▲
                    │  GET /documents/status (polled while any doc is in flight)
                 Browser
```

**Web and worker are separate containers, and that has a load-bearing consequence:** the worker must
re-read the file the web container wrote, and container-local disk does not survive that boundary.
So `FILESYSTEM_DISK` is environment-driven from day one — a shared named volume in Compose, S3 or GCS
in a real deployment. Writing to `storage/app` inside one container works on a laptop and breaks the
moment it is deployed; that trap is designed out rather than discovered later.

They also scale independently for a real reason: the web tier is latency-bound, while extraction is
bound by the OpenAI rate limit. Those two workloads want different numbers of replicas.

## Stack

| Layer | Choice | Why |
|---|---|---|
| Backend | Laravel 13, PHP 8.4 | Matches SupplyScope's stack |
| Frontend | Inertia + React 19 + TypeScript | Server-driven routing without building a separate API |
| Database | PostgreSQL 17 | `jsonb` for extracted payloads; UTF8 throughout |
| Queue / cache / session | Redis 7 | Real queue with retries, backoff and a failed-jobs table |
| Runtime | FrankenPHP | One process serving HTTP — no nginx + php-fpm + supervisor sandwich |
| Containers | Docker Compose | Multi-stage build; web, worker, migrate and test from one image |
| Auth | Hand-rolled session login | One env-seeded account; Breeze/Fortify would drag in registration and password reset |
| Tests | Pest + Vitest | |

## Data model

Three tables, deliberately separating *the file*, *the result*, and *each attempt at producing it*:

- **`documents`** — the uploaded file: filename, MIME type, size, `sha256`, page count, disk and
  path, status, failure code and reason, attempt count, timing. The `disk` is stored per document, so
  files written under a local disk stay readable after a later migration to S3.
- **`extractions`** — one row per successfully extracted document, holding the structured result.
- **`extraction_attempts`** — one row per attempt, successful or not: model, prompt version, outcome,
  error class, HTTP status, latency and token counts. This is the observability surface — it answers
  "why did this document fail?" without reading logs.

`sha256` is what makes *"what if the same file is uploaded twice"* and *"what if the job runs twice"*
answerable rather than hopeful. Deduplication is scoped **per owner**, never global — matching on the
hash alone would hand one user another user's extraction.

Documents are owned, and a user only ever sees their own. Ownership is stored polymorphically
(`owner_type` / `owner_id`) and every query resolves the owner through a single seam, so
organisation-level tenancy is a later data migration rather than a rewrite. `uploaded_by_user_id` is
tracked separately from the owner, because under tenancy an organisation owns a document that a person
uploaded.

## Access control

The app is behind a login. That is a deliberate addition rather than a requirement of the brief, and
the reason is the API key: every upload is a vision-model call billed to the key that ships with this
project. An unauthenticated upload form on a public URL is an open invitation to spend someone else's
money, and to feed the model documents containing injected instructions whose output this app then
stores and renders.

- **A single login, seeded from the environment.** `php artisan app:ensure-admin` reads `ADMIN_EMAIL`,
  `ADMIN_NAME` and `ADMIN_PASSWORD`, hashes the password, and is idempotent — re-running it rotates
  the credential rather than creating a second account. If `ADMIN_PASSWORD` is unset it creates
  nothing and warns; a predictable default would be worse than no account at all.
- **No self-registration, no password reset.** Neither is asked for, and each is another surface to
  secure. Registration in particular would reopen exactly the hole the login closes.
- **Per-route rate limits**, sized to what each route actually does. Login is limited per email and IP
  against credential stuffing; uploads are limited because each accepted file costs an API call, which
  makes it a spend control rather than a load control; the status endpoint is deliberately *generous*
  because the UI polls it every 2.5 seconds per open tab.
- **A daily extraction ceiling**, so a leaked credential cannot run up an unbounded bill overnight.

Only the login page and the `/up` health endpoint are public.

## Getting started

🚧 *Filled in once the container layer is complete.*

## Configuration

All configuration is environment-driven — no hostnames, ports or credentials are hardcoded. Copy
`.env.example` to `.env` and fill in the blanks.

| Variable | Purpose |
|---|---|
| `OPENAI_API_KEY` | API key. Injected at runtime; never baked into an image layer. |
| `OPENAI_MODEL` | Extraction model (default `gpt-5.5`). |
| `OPENAI_BASE_URL` | Overridable so a deployed instance can be pointed at a mock. |
| `FILESYSTEM_DISK` | Where uploads live. Shared volume in dev, S3/GCS in production. |
| `QUEUE_CONNECTION` | Redis. Never `sync` — the worker is a separate process, by design. |
| `EXTRACTION_TIMEOUT` | HTTP timeout for the model call (90s). |
| `EXTRACTION_JOB_TIMEOUT` | Queue job timeout (120s), deliberately above the HTTP timeout. |
| `UPLOAD_MAX_FILE_SIZE_KB` | Per-file upload cap. |
| `UPLOAD_MAX_PDF_PAGES` | Page cap — a very long PDF is a latency and abuse vector. |
| `ADMIN_EMAIL` / `ADMIN_NAME` | Identity of the single seeded account. |
| `ADMIN_PASSWORD` | Seeded password, hashed on write. Leave unset and no account is created. |
| `EXTRACTION_DAILY_LIMIT` | Hard ceiling on extractions per day — a spend control. |

## Testing

🚧 *Filled in at the testing stage.*

## Deployment

🚧 *Filled in once the container layer is complete.*

Nothing is currently hosted, but deploy-readiness is a hard design goal: the stack is containerised
and twelve-factor throughout, so hosting it is a configuration exercise rather than a rewrite.

## What was cut, and why

🚧 *Filled in at the end.* Scope was deliberately traded to spend the time on failure handling and
tests instead.

## Build status

- [x] Laravel 13 scaffold, dependencies, twelve-factor environment
- [x] Container layer — Dockerfile, Compose stack
- [x] Inertia + React 19 + TypeScript wiring
- [x] Domain model and migrations
- [x] Access control — login gate, env-seeded admin, rate limiting
- [x] Upload and validation
- [x] Queue, job and reliability
- [x] LLM extraction layer
- [ ] Frontend
- [ ] Tests
- [ ] `DECISIONS.md`
