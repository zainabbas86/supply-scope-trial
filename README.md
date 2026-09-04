# Label Extraction Agent

Upload a product label or specification sheet; a vision LLM reads it and returns structured product
data — product name, brand, ingredients, allergens and net weight — which the app stores and renders
field by field, with the page each value came from.

Built for SupplyScope's Stage-1 developer trial.

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

Docker is the only requirement — no PHP, Node, Postgres or Redis on the host.

```bash
cp .env.example .env          # set OPENAI_API_KEY, ADMIN_PASSWORD, DB/REDIS passwords
docker compose up -d          # postgres, redis, web, worker
docker compose run --rm migrate
docker compose run --rm web php artisan app:ensure-admin
```

Then open **http://localhost:8080** and sign in with `ADMIN_USERNAME` / `ADMIN_PASSWORD`.

Three things worth knowing before the first run:

- **`ADMIN_PASSWORD` has no default.** Leave it unset and `app:ensure-admin` creates
  *nothing* and says so. A guessable fallback on a reachable host is worse than no way in.
  Minimum 12 characters; the command fails loudly on a shorter one.
- **`REDIS_PASSWORD` must be a real value, never the literal `null`.** Compose passes it
  verbatim to `redis-server --requirepass`, while PHP dotenv converts an unquoted `null` to
  PHP `null` — so Redis demands a password Laravel never sends. Compose refuses to start if
  it is unset.
- **Port 8080, not 80.** The container runs as a non-root user, which cannot bind a
  privileged port. Change `APP_PORT` if 8080 is taken, and keep `APP_URL` in step.

Migrations run **explicitly**, never on container boot — several replicas booting together
would race them against each other.

### Development

```bash
docker compose up -d          # docker-compose.override.yml bind-mounts the source
npm run dev                   # Vite, for frontend work
docker compose logs -f worker # follow extractions
```

`docker-compose.override.yml` is applied automatically and trades the image immutability
for a fast edit loop. To run the *deployed* shape — code baked in, nothing mounted — use
`docker compose -f docker-compose.yml up`.

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
| `ADMIN_USERNAME` / `ADMIN_NAME` | Identity of the single seeded account. The username is an opaque identifier — it does not have to be an email address. |
| `ADMIN_PASSWORD` | Seeded password, hashed on write. Leave unset and no account is created. |
| `EXTRACTION_DAILY_LIMIT` | Hard ceiling on extractions per day — a spend control. |

## Testing

```bash
docker compose run --rm test   # 102 PHP tests, in the image, against real Postgres
npm run test                   # 36 component tests (Vitest + Testing Library)
npm run typecheck              # tsc --noEmit — SEPARATE from build, see below
composer analyse               # PHPStan level 6
composer lint                  # Pint
```

**138 tests in total**, built to fail for the right reasons:

- **Real PostgreSQL, not SQLite in memory.** SQLite fakes `jsonb` as text and stores
  everything UTF-8 regardless, which would make both of those assertions meaningless. Tests
  use a separate `label_extractor_test` database — `RefreshDatabase` drops every table it
  can see, which against the development database would be an unpleasant surprise.
- **No test reaches the network.** A scriptable `FakeLabelExtractor` is bound in place of
  the OpenAI client, and `OPENAI_API_KEY` is blanked in the test environment — so a test
  that somehow escaped the fake fails loudly on a 401 rather than quietly spending money.
- **The provider client is still tested**, against a **real captured `gpt-5.5` response**
  recorded live and committed as a fixture. A hand-written fixture only proves the code
  agrees with my own assumptions about the API.
- **Failure paths, not happy paths.** Retry-then-succeed, terminal-without-retrying,
  idempotency, concurrent workers, malformed and truncated and refused model output,
  cross-owner isolation, and content-sniffed upload rejection.
- **`npm run build` does not type-check.** esbuild strips types without checking them, so a
  build can be green on code that does not compile. `typecheck` is a separate gate, and the
  Docker `assets` stage runs it before building.

The suite runs in a dedicated `test` image stage — the runtime image installs `--no-dev`, so
it has neither Pest nor PHPUnit and could not run a test if asked.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request, in three parallel jobs:

| Job | Gates |
|---|---|
| **backend** | Pint, PHPStan level 6, Pest — against real Postgres and Redis service containers |
| **frontend** | `tsc --noEmit`, Vitest, production build |
| **image** | Builds the `runtime` and `test` stages |

The image job earns its place: it is the only gate that runs on **Linux with a
case-sensitive filesystem**. Development here is on Windows, where a case-only path
difference resolves silently and then fails in the container — which has already happened
once, with `resources/js/Pages` versus the lowercase `pages` that inertia-laravel expects.

`.github/workflows/deploy.yml` builds the image **once** and promotes that same artifact to
`uat`, `staging` or `production` — the thing that passed CI is the thing that ships.
Production deploys on a version tag; the others are manual.

### Secrets

`.env.example` contains **no credentials**. Every secret is a GitHub Environment secret, so
each environment holds its own and can be rotated without touching the repository — and a
UAT credential is unreadable from a production job.

Set these under **Settings → Environments → *(uat | staging | production)***:

| Secret | |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` |
| `DB_PASSWORD`, `REDIS_PASSWORD` | Managed-service credentials |
| `OPENAI_API_KEY` | Billed per extraction — scope it per environment |
| `ADMIN_PASSWORD` | Minimum 12 characters. Blank creates **no** account. |
| `DEPLOY_TOKEN` | Host credential for the release step |

The deploy job checks all of them **before** touching the host, so a missing secret fails
with a list of what is absent rather than deploying an app that cannot boot or has no way in.

**Rotating the login is: change `ADMIN_PASSWORD`, re-run the workflow.** `app:ensure-admin`
is idempotent and keyed on the username, so it updates the password rather than creating a
second account. There is deliberately no in-app password change — the account is
provisioned, not self-managed.

## Deployment

**Nothing is currently hosted.** Deploy-readiness was still a hard design goal, so what
remains is configuration rather than architecture — see
[DECISIONS.md §4](DECISIONS.md#4-deployment-posture) for the detail.

The image and Compose file map 1:1 onto Fly.io, Render, Railway or Cloud Run: one web
service and one worker service **from the same image**, managed Postgres and Redis, S3 or
GCS for `FILESYSTEM_DISK`, and migrations as a release command. Nothing is hardcoded, logs
go to stdout, and `config:cache` is deliberately not run at build time — that would bake
build-time environment values into the image.

The deploy workflow is wired as far as it honestly can be without a host: it builds and
publishes the image to GHCR for real, and each environment has a single explicit `Release`
step where the provider command goes (`flyctl deploy --image …`, a Render deploy hook, or
`gcloud run deploy`). That step **fails loudly rather than reporting a success it did not
achieve** — a green deploy that deployed nothing is worse than an obvious red one.

Still required before a real deployment: a secret store rather than environment strings, a
readiness probe that checks Postgres and Redis rather than only liveness, and somewhere for
logs and queue-depth metrics to go.

## What was cut, and why

Scope was traded deliberately, to spend the time on failure handling and tests instead.

| Cut | Why, and what I would do instead |
|---|---|
| **Hosting it** | Optional in the brief. The stack is deploy-ready; hosting is configuration, and the login gate exists precisely so it *could* be hosted safely. |
| **Organisation-level tenancy** | Per-user ownership ships. The schema is polymorphic and every ownership question goes through one resolver, so tenancy is a new model plus a data migration, not a rewrite. |
| **User registration, password reset, roles** | A single env-provisioned login instead. Registration in particular would reopen the exact hole the login closes — anyone could sign up and spend the API key. |
| **WebSocket broadcasting** | Polling every 2.5s, only while work is in flight. Reverb means a third container plus sticky sessions, for a list that is idle almost all of the time. |
| **Credits and metering** | A daily extraction ceiling covers the actual risk (an unbounded bill). Per-user quotas need a tenancy model first. |
| **OCR fallback for poor scans** | The vision model handles the sample documents. A genuinely illegible scan returns nulls with a warning, which is the honest answer. |
| **Multi-model routing / fallback** | One model, recorded per attempt so a change is attributable. A fallback chain doubles the failure surface for a case that has not occurred. |
| **Versioned extraction history** | One row per document; re-running replaces it. Better for auditing prompt changes, but a column and a scope away when needed. |
| **Playwright E2E** | Component tests cover the UI states and feature tests cover the routes. E2E would mostly re-test what those already do. |
| **i18n** | Single locale. Messages are literal strings rather than translation keys, which is why they read as sentences. |

Two smaller things, named so they read as decisions rather than oversights:

- **Upload feedback survives one redirect.** Rejection and duplicate notices are flashed, so
  navigating away clears them. Standard flash behaviour, and right for a transient notice.
- **`/documents/status` returns the full list**, not a delta. At 100 documents that is a few
  KB; at 10,000 it would want pagination and change-only responses.

## Build status

- [x] Laravel 13 scaffold, dependencies, twelve-factor environment
- [x] Container layer — Dockerfile, Compose stack
- [x] Inertia + React 19 + TypeScript wiring
- [x] Domain model and migrations
- [x] Access control — login gate, env-seeded admin, rate limiting
- [x] Upload and validation
- [x] Queue, job and reliability
- [x] LLM extraction layer
- [x] Frontend
- [x] Tests
- [x] `DECISIONS.md`
