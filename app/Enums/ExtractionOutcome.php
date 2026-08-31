<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The result of a single attempt at extracting one document.
 *
 * The distinction that matters is retryable vs terminal. Burning all three
 * retries on an HTTP 400 that will never succeed wastes the retry budget and
 * delays the failure the user is waiting to see; retrying nothing on a 429
 * throws away work that would have succeeded seconds later.
 */
enum ExtractionOutcome: string
{
    /** Valid, schema-conforming data was persisted. */
    case Success = 'success';

    /** Transient: 429, 5xx, connect/read timeout. Throw and let the queue retry. */
    case RetryableError = 'retryable_error';

    /** Permanent: 400, 401, 403, unsupported content. Fail immediately, no retries. */
    case TerminalError = 'terminal_error';

    /** The model answered, but the payload failed server-side validation even after a repair pass. */
    case InvalidOutput = 'invalid_output';

    public function isRetryable(): bool
    {
        return $this === self::RetryableError;
    }

    public function isFailure(): bool
    {
        return $this !== self::Success;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
