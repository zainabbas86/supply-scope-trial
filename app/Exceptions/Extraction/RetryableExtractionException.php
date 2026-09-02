<?php

declare(strict_types=1);

namespace App\Exceptions\Extraction;

/**
 * Transient. Throwing this lets the queue retry with backoff.
 *
 * Rate limits, 5xx, connect and read timeouts. The defining question is not
 * "was this an error?" but "would the identical request plausibly succeed in
 * thirty seconds?".
 */
class RetryableExtractionException extends ExtractionException
{
    /** @param array<string, mixed>|null $raw */
    public static function rateLimited(?int $retryAfter = null, ?array $raw = null): self
    {
        return new self(
            'rate_limited',
            'The AI service is busy. This will be retried automatically.',
            'OpenAI returned 429'.($retryAfter ? " (retry after {$retryAfter}s)" : ''),
            429,
            $raw,
        );
    }

    /** @param array<string, mixed>|null $raw */
    public static function serverError(int $status, ?array $raw = null): self
    {
        return new self(
            'provider_error',
            'The AI service is temporarily unavailable. This will be retried automatically.',
            "OpenAI returned {$status}",
            $status,
            $raw,
        );
    }

    public static function timedOut(int $seconds): self
    {
        return new self(
            'timeout',
            'The AI service did not respond in time. This will be retried automatically.',
            "Request exceeded {$seconds}s",
            null,
        );
    }

    public static function connectionFailed(string $detail): self
    {
        return new self(
            'connection_failed',
            'Could not reach the AI service. This will be retried automatically.',
            $detail,
            null,
        );
    }
}
