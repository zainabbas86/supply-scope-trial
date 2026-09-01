<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\ExtractionOutcome;
use App\Exceptions\Extraction\ExtractionException;
use App\Exceptions\Extraction\RetryableExtractionException;
use App\Exceptions\Extraction\TerminalExtractionException;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\ExtractionAttempt;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\LabelExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Throwable;

/**
 * Extract structured product data from one document.
 *
 * Runs in a SEPARATE container from the web process. Two consequences shape
 * everything here:
 *
 *  - There is no authenticated user, so ownership is read from the document
 *    itself. This is why `Document` uses an explicit `forOwner()` scope rather
 *    than a global scope keyed on auth().
 *  - The file is re-read from shared storage; nothing the web container wrote
 *    to container-local disk would be visible.
 */
class ExtractLabelData
{
    use AsJob;

    /*
     * Constructor injection, NOT a second handle() argument.
     *
     * laravel-actions passes only the DISPATCH arguments to handle() — it does
     * not resolve extra type-hinted dependencies from the container the way a
     * controller action would. `handle(Document $d, LabelExtractor $e)` looks
     * perfectly reasonable and fails at runtime with "Too few arguments".
     *
     * It survived the test suite because the tests called handle($doc, $fake)
     * directly, supplying both arguments themselves and never exercising the
     * real dispatch path. Only running a job through the queue caught it.
     */
    public function __construct(
        private readonly LabelExtractor $extractor,
    ) {}

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(config('extraction.queue'))
            ->setTries((int) config('extraction.max_tries'))
            // Must stay BELOW queue.connections.redis.retry_after (180s), or
            // Redis redelivers a job that is still running.
            ->setTimeout((int) config('extraction.job_timeout'));
    }

    /**
     * Backoff with jitter.
     *
     * Fixed backoff synchronises every retry: an OpenAI outage that fails 200
     * jobs at once would have all 200 retry at exactly t+10, t+30, t+90 — a
     * thundering herd that guarantees the next failure too. The random spread
     * de-synchronises them.
     *
     * NOTE the method name. laravel-actions reads `getJobBackoff` from the
     * action, not Laravel's `backoff` — a plain `backoff()` here is silently
     * ignored and the job retries with no delay at all. Same for
     * getJobRetryUntil, getJobMiddleware and jobFailed below.
     *
     * @return list<int>
     */
    public function getJobBackoff(): array
    {
        return [
            10 + random_int(0, 5),
            30 + random_int(0, 15),
            90 + random_int(0, 30),
        ];
    }

    /**
     * A wall-clock deadline independent of the retry count.
     *
     * `$tries` alone does not bound total time: three tries with backoff and a
     * 120s timeout each could span far longer than anyone waiting would accept.
     * Whichever limit is reached first ends the job.
     */
    public function getJobRetryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(15);
    }

    /**
     * Shed load rather than hammering a provider that is already refusing us.
     *
     * @return list<object>
     */
    public function getJobMiddleware(): array
    {
        // NOT ->dontRelease(): that makes the middleware return false, which
        // DELETES a rate-limited job instead of deferring it. Silently
        // dropping work is far worse than waiting. The default releases the
        // job back to the queue until the limiter allows it through.
        return [
            new RateLimited('extraction'),
        ];
    }

    public function handle(Document $document): void
    {
        Log::withContext(['document_id' => $document->id]);

        // ------------------------------------------------------------------
        // Idempotency gate 1: has this already finished?
        //
        // A redelivered message for a completed document must not re-extract
        // and re-bill. Redis redelivers whenever a worker dies mid-job, so
        // this is an ordinary event, not an edge case.
        // ------------------------------------------------------------------
        $document->refresh();

        if ($document->status->isTerminal()) {
            Log::info('Skipping: document already terminal.', ['status' => $document->status->value]);

            return;
        }

        // ------------------------------------------------------------------
        // Idempotency gate 2: claim the document atomically.
        //
        // A conditional UPDATE ... WHERE status = 'queued' is the actual race
        // guard. Two workers can both pass the check above; only one can win
        // this UPDATE, because the database evaluates the predicate and the
        // write as a single operation. The loser sees 0 affected rows and
        // exits cleanly.
        //
        // A read-then-write ("if queued, set processing") would look identical
        // and be wrong — both workers would read 'queued' before either wrote.
        // ------------------------------------------------------------------
        $claimed = Document::whereKey($document->id)
            ->where('status', DocumentStatus::Queued->value)
            ->update([
                'status' => DocumentStatus::Processing->value,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            Log::info('Skipping: another worker already claimed this document.');

            return;
        }

        $document->refresh();
        $attemptNo = (int) $document->attempts;
        $startedAt = hrtime(true);

        try {
            $this->assertFileIsReadable($document);

            $result = $this->extractor->extract($document);

            $this->persistSuccess($document, $result, $attemptNo, $startedAt);

            Log::info('Extraction completed.', ['attempt' => $attemptNo]);
        } catch (ExtractionException $e) {
            $this->recordAttempt($document, $attemptNo, $e, $startedAt);

            if ($e instanceof RetryableExtractionException) {
                // Return the document to `queued` so the retry can claim it
                // again. Leaving it in `processing` would make gate 2 reject
                // every subsequent attempt — the job would silently stop
                // retrying while appearing to still be working.
                $document->update(['status' => DocumentStatus::Queued->value]);

                Log::warning('Retryable failure; releasing for retry.', [
                    'attempt' => $attemptNo,
                    'code' => $e->failureCode,
                ]);

                // Rethrow: the queue owns the backoff and the retry count.
                throw $e;
            }

            // Terminal: record the failure and RETURN rather than rethrow.
            //
            // Rethrowing would consume the remaining retries on an error that
            // cannot succeed — a 400 is still a 400 on attempt three — and
            // would delay the user's answer by two backoff periods for nothing.
            // Returning normally means the queue considers the job done, which
            // it is: the outcome is recorded on the document and in
            // extraction_attempts. `failed_jobs` is for UNEXPECTED failures;
            // a provider rejecting a document is an expected, handled outcome.
            $this->markFailed($document, $e->failureCode, $e->userMessage);

            Log::error('Terminal failure; not retrying.', ['code' => $e->failureCode]);
        }
    }

    /**
     * The last attempt has failed, or the deadline passed.
     *
     * Called by the queue after retries are exhausted, and it is the only place
     * that can distinguish "still retrying" from "genuinely finished failing".
     * Without it a document that ran out of retries would sit in `queued`
     * forever, looking like it was about to be processed.
     *
     * Named `jobFailed`, and note the argument order: laravel-actions invokes
     * it as [$exception, ...$jobParameters]. A Laravel-style `failed()` on an
     * action is never called at all.
     */
    public function jobFailed(Throwable $e, Document $document): void
    {
        $document->refresh();

        if ($document->status->isTerminal()) {
            return;
        }

        // A retryable exception's message is written for the IN-FLIGHT case
        // ("this will be retried automatically"). Reusing it here would tell
        // the user a retry is coming when the retries are already spent — the
        // document would sit there promising something that will never happen.
        // Terminal errors keep their own wording, which is already final.
        $tries = (int) config('extraction.max_tries');

        [$code, $message] = match (true) {
            $e instanceof RetryableExtractionException => [
                $e->failureCode,
                "The AI service could not process this document after {$tries} attempts. You can retry it.",
            ],
            $e instanceof ExtractionException => [$e->failureCode, $e->userMessage],
            default => ['unexpected_error', 'Something went wrong while reading this document.'],
        };

        $this->markFailed($document, $code, $message);

        Log::error('Extraction failed after all attempts.', ['code' => $code]);
    }

    // -----------------------------------------------------------------------

    private function assertFileIsReadable(Document $document): void
    {
        // Read through the disk recorded ON THE DOCUMENT, not the current
        // default. A document uploaded before a migration to S3 must stay
        // readable afterwards.
        if (! Storage::disk($document->disk)->exists($document->storage_path)) {
            throw TerminalExtractionException::fileMissing($document->storage_path);
        }
    }

    private function persistSuccess(
        Document $document,
        ExtractionResult $result,
        int $attemptNo,
        int $startedAt,
    ): void {
        $latency = $result->latencyMs ?? $this->elapsedMs($startedAt);

        DB::transaction(function () use ($document, $result, $attemptNo, $latency) {
            // updateOrCreate, not create: combined with the unique index on
            // extractions.document_id this makes a double-run produce exactly
            // one row instead of a constraint violation.
            Extraction::updateOrCreate(
                ['document_id' => $document->id],
                $result->toExtractionAttributes(),
            );

            ExtractionAttempt::create([
                'document_id' => $document->id,
                'attempt_no' => $attemptNo,
                'model' => $result->model,
                'prompt_version' => $result->promptVersion,
                'outcome' => ExtractionOutcome::Success,
                'http_status' => 200,
                'latency_ms' => $latency,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'raw_response' => $result->raw,
            ]);

            // The terminal status update is the LAST statement in the
            // transaction. Nothing may observe a document as `completed` before
            // its extraction row exists — the UI would render an empty result.
            $document->update([
                'status' => DocumentStatus::Completed->value,
                'finished_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ]);
        });
    }

    private function recordAttempt(
        Document $document,
        int $attemptNo,
        ExtractionException $e,
        int $startedAt,
    ): void {
        ExtractionAttempt::updateOrCreate(
            ['document_id' => $document->id, 'attempt_no' => $attemptNo],
            [
                'model' => (string) config('extraction.model'),
                'prompt_version' => (string) config('extraction.prompt_version'),
                'outcome' => $e instanceof RetryableExtractionException
                    ? ExtractionOutcome::RetryableError
                    : ($e->failureCode === 'invalid_output'
                        ? ExtractionOutcome::InvalidOutput
                        : ExtractionOutcome::TerminalError),
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'http_status' => $e->httpStatus,
                'latency_ms' => $this->elapsedMs($startedAt),
                'raw_response' => $e->rawResponse,
            ],
        );
    }

    private function markFailed(Document $document, string $code, string $reason): void
    {
        $document->update([
            'status' => DocumentStatus::Failed->value,
            'failure_code' => $code,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
