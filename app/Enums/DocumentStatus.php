<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an uploaded document.
 *
 * A backed enum rather than a bare string so that an invalid status cannot be
 * constructed at all: the failure happens at the boundary (cast, validation)
 * instead of leaking into a `if ($doc->status === 'complete')` typo that is
 * silently always false.
 */
enum DocumentStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Terminal states never transition again.
     *
     * The queue job checks this before doing any work: a redelivered message
     * for an already-finished document must exit rather than re-extract.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Queued, self::Processing => false,
        };
    }

    /** Still moving — this is what the UI polls on. */
    public function isInFlight(): bool
    {
        return ! $this->isTerminal();
    }

    /** Human-facing label for the status badge. */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
