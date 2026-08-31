<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Decorators\JobDecorator;

/**
 * Extract structured product data from one document.
 *
 * ---------------------------------------------------------------------------
 * SHELL — §3 fills in handle(). It is dispatched for real from §2 so the queue
 * path (web container -> Redis -> worker container) is exercised and verifiable
 * before the extraction logic exists. Retry classification, idempotency, the
 * conditional status transition and failure reasons all land in §3.
 * ---------------------------------------------------------------------------
 *
 * Runs in a SEPARATE container from the web process. Two consequences that
 * shape everything here:
 *
 *  - There is no authenticated user. Ownership must be read from the document
 *    itself, never from auth() — which is why Document uses an explicit
 *    forOwner() scope rather than a global scope.
 *  - The file must be re-read from shared storage. The worker cannot see
 *    anything the web container wrote to container-local disk.
 */
class ExtractLabelData
{
    use AsJob;

    /**
     * Route to the dedicated extraction queue and take the retry budget from
     * config, so the worker container's `--queue=` and `--tries=` flags and
     * this class cannot drift apart.
     */
    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(config('extraction.queue'))
            ->setTries((int) config('extraction.max_tries'))
            ->setTimeout((int) config('extraction.job_timeout'));
    }

    public function handle(Document $document): void
    {
        // Structured context on every line this job logs, so a single
        // document's journey can be followed through stdout in a container
        // platform without a correlation id bolted on later.
        Log::withContext(['document_id' => $document->id]);

        Log::info('Extraction job received.', [
            'status' => $document->status->value,
            'attempts' => $document->attempts,
        ]);

        // §3: conditional queued -> processing transition, extractor call,
        // validation, persistence, retry classification, failure reasons.
    }
}
