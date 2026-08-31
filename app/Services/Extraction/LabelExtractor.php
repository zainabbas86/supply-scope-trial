<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Exceptions\Extraction\RetryableExtractionException;
use App\Exceptions\Extraction\TerminalExtractionException;
use App\Models\Document;

/**
 * Turns a stored document into structured product data.
 *
 * An interface, and the reliability layer in §3 depends only on this — which is
 * what lets the queue behaviour (retries, idempotency, failure classification)
 * be built and tested with no network at all. The brief requires that tests
 * never call the real API; that is a consequence of this seam, not something
 * bolted on afterwards.
 *
 * Implementations are responsible for CLASSIFYING their failures. Deciding
 * whether an error is worth retrying needs provider knowledge (which HTTP
 * statuses are transient, what a refusal looks like) that the job has no
 * business encoding. The job only asks: retryable, or not?
 */
interface LabelExtractor
{
    /**
     * @throws RetryableExtractionException transient — the queue should try again
     * @throws TerminalExtractionException permanent — retrying will not help
     */
    public function extract(Document $document): ExtractionResult;
}
