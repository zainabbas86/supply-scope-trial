<?php

declare(strict_types=1);

namespace App\Exceptions\Extraction;

use RuntimeException;

/**
 * Base for extraction failures.
 *
 * The type of the exception IS the retry decision. That is the point: the job
 * does not inspect HTTP status codes or match on error strings, it catches one
 * of two subclasses. Provider-specific knowledge stays inside the extractor,
 * and the reliability logic stays readable.
 *
 * Every failure carries a machine `failureCode` and a sentence written for the
 * person staring at the screen — the same split as `documents.failure_code` /
 * `failure_reason`.
 */
abstract class ExtractionException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $userMessage,
        string $technicalMessage,
        public readonly ?int $httpStatus = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $rawResponse = null,
    ) {
        parent::__construct($technicalMessage);
    }
}
