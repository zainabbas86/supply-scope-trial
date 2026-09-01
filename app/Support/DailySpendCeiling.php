<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExtractionAttempt;

/**
 * A hard cap on extractions started per day.
 *
 * Rate limiting bounds the BURST, not the total. `THROTTLE_UPLOAD_PER_MINUTE=20`
 * sustained overnight is roughly 28,000 model calls — every one of them billed
 * to the API key. This is the control that stops a leaked credential, a runaway
 * script or a retry loop producing an invoice nobody authorised.
 *
 * Counted against `extraction_attempts` rather than documents, because an
 * attempt is what actually costs money: a document retried three times is three
 * calls, not one.
 *
 * Counted GLOBALLY rather than per owner, because the quota and the bill belong
 * to the API key, not to a user. Under real multi-tenancy this would gain a
 * per-tenant fairness quota on top, so one bulk importer cannot consume the
 * whole day's budget — that is noted in DECISIONS.md as the scaling answer, not
 * built here.
 */
final class DailySpendCeiling
{
    public function limit(): int
    {
        return (int) config('access.extraction_daily_limit');
    }

    public function used(): int
    {
        return ExtractionAttempt::whereDate('created_at', today())->count();
    }

    public function remaining(): int
    {
        return max(0, $this->limit() - $this->used());
    }

    /**
     * A limit of 0 or less disables the ceiling entirely, which is the right
     * behaviour for a test environment that should not be tripped by it.
     */
    public function hasCapacity(): bool
    {
        return $this->limit() <= 0 || $this->used() < $this->limit();
    }
}
