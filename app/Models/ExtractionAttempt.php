<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractionOutcome;
use Database\Factories\ExtractionAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt at extracting one document — successful or not.
 *
 * This is the observability surface. Logs rotate and are awkward to query;
 * this table answers "why did this fail, how long did it take, and what did it
 * cost" from the database.
 *
 * @property ExtractionOutcome $outcome
 */
class ExtractionAttempt extends Model
{
    /** @use HasFactory<ExtractionAttemptFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => ExtractionOutcome::class,
            'attempt_no' => 'integer',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'raw_response' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function succeeded(): bool
    {
        return $this->outcome === ExtractionOutcome::Success;
    }

    /** Total tokens billed for this attempt, when the provider reported them. */
    public function totalTokens(): ?int
    {
        if ($this->input_tokens === null && $this->output_tokens === null) {
            return null;
        }

        return (int) $this->input_tokens + (int) $this->output_tokens;
    }
}
