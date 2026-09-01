# Working in this repository

Instructions for anyone — human or AI agent — making changes here. Read this before
editing; several of the rules below exist because breaking them produced a bug that
looked like something else entirely.

## How this codebase was built

This application was written with AI assistance (Claude), pair-style: architecture and
every non-obvious decision were reviewed and driven deliberately, and each section was
verified against the running system rather than accepted because it compiled. The commit
history is the honest record of that — including the bugs found and fixed along the way.

Stated plainly because it is better asked and answered directly than guessed at, and
because the reasoning behind every decision here is documented well enough to defend in
conversation. `DECISIONS.md` covers the *why* for the architectural choices.

## What this is

Upload a product label or specification sheet → a real Redis queue hands it to a
**separate worker container** → a vision model extracts structured product data →
the result is stored and rendered field by field with the page each value came from.

Read `README.md` for the domain and `DECISIONS.md` for the trade-offs.

## Commands

Docker is the contract. Herd is a convenience for the inner loop.

```bash
docker compose up -d                                   # postgres, redis, web, worker
docker compose run --rm migrate                        # never on container boot
docker compose run --rm web php artisan app:ensure-admin
docker compose run --rm test                           # the full suite, in the image
docker compose logs -f worker                          # follow extraction

npm run dev          # vite, for frontend work
npm run typecheck    # tsc --noEmit — SEPARATE from build, see below
./vendor/bin/pint    # formatting
./vendor/bin/pest    # tests on the host
```

`php`, `composer` and `artisan` come from Herd as `.bat` shims: they resolve in
**PowerShell, not Git Bash**. Node and npm work in either.

## Hard rules

**The worker is a separate process in a separate container. Never `QUEUE_CONNECTION=sync`.**
Two consequences that break code written without them in mind: there is no authenticated
user in a job, so ownership is read from the record (never `auth()`); and the file must be
re-read from shared storage, so always go through the `Storage` facade with the disk
recorded *on the document*.

**Never trust model output.** `strict: true` guarantees the shape, not the meaning. Every
payload goes through `ExtractionValidator` before it is persisted. The repair pass is
bounded at exactly one round trip.

**Never trust an uploaded file's name or declared type.** The content type is sniffed from
the bytes with `finfo`; the extension and the browser's `Content-Type` are both attacker-
controlled.

**Never let dedupe cross an owner.** The index is `(owner_type, owner_id, sha256)`. A
global hash match would hand one user another user's extraction. The index shape *is* the
security boundary.

## Traps that have already cost time here

Each of these passed review, compiled, and failed at runtime.

| Trap | What happens |
|---|---|
| **Page directory must be lowercase `resources/js/pages`** | `inertia-laravel` defaults to `resource_path('js/pages')`. Capital `P` works on Windows (case-insensitive) and fails in the Linux container. **Any case-only difference is invisible locally and fatal in the container.** |
| **`lorisleiva/laravel-actions` uses its own job hook names** | `getJobBackoff`, `getJobRetryUntil`, `getJobMiddleware`, `jobFailed(Throwable $e, ...$params)`. Laravel-style `backoff()` / `retryUntil()` / `middleware()` / `failed()` are **silently ignored** — no error, just no backoff and no failure handler. |
| **`handle()` receives only the dispatch arguments** | It does not resolve extra type-hinted dependencies from the container. Inject them in the constructor. |
| **`$this->authorize()` does not exist** | Laravel 11+ ships a bare base `Controller`. Use `Gate::authorize()`. |
| **`vite build` does not type-check** | esbuild strips types without checking them. `npm run typecheck` is a separate gate; without it TypeScript is decoration. |
| **`queue.retry_after` must exceed the job timeout** | The framework default (90s) is *below* our 120s job timeout, so Redis redelivers a still-running job: two workers, one document, two bills. Ordering is `http 90 < job 120 < retry_after 180`. |
| **`REDIS_PASSWORD=null` is a trap, not a value** | Compose passes the literal string to `--requirepass`; PHP's dotenv converts it to PHP `null`. Redis then demands a password Laravel never sends. |
| **`documents.attempts` is a column, not the relation** | Eloquent resolves the attribute first. The relation is `extractionAttempts()`. |
| **PHP upload limits must match `config/uploads.php`** | PHP discards an oversized body *before* any application code runs, handing over an empty `$_FILES`. The failure looks like a validation bug and is not one. |
| **Uploads fail under Herd's `artisan serve` on Windows** | `unable to create a temporary file` — a local `upload_tmp_dir` problem. Verify upload flows against Docker. |

## Conventions

- **Actions** (`lorisleiva/laravel-actions`) for work that runs both as a route target and
  from a test without an HTTP request. Controllers for plain read paths.
- **Backed enums** carry behaviour, not just values — `DocumentStatus::isTerminal()` is the
  redelivery guard, `ExtractionOutcome::isRetryable()` is the retry decision.
- **Two-part failures everywhere**: a stable machine code to branch on, and a sentence
  written for the person waiting. Never show an exception class to a user.
- **Comments explain *why*, never *what*.** The code says what it does.
- **TypeScript types in `resources/js/types/` are hand-maintained mirrors** of the PHP
  payloads. Nothing enforces the match — rename a key server-side and the type-checker
  stays green while the UI renders `undefined`. Change one, change the other.

## Testing

Tests run against **real PostgreSQL** on a separate `label_extractor_test` database, not
SQLite in memory: SQLite fakes `jsonb` as text and stores everything UTF-8 regardless,
which would make both of those assertions meaningless.

`FakeLabelExtractor` is bound in place of the OpenAI client, and `OPENAI_API_KEY` is
blanked in the test environment — so a test that somehow reached the network fails loudly
instead of quietly spending money.

**Test the wiring, not just the logic.** Two runtime failures shipped past 83 passing tests
because every test called `handle()` directly and supplied its own arguments. Feature tests
that go through the actual route or `dispatchSync()` are what catch that class of bug.

Never assert an exact `source_page` from the model — it legitimately varies between runs
when the same text appears on more than one page. Assert the stable fields and validate
citations by range.
