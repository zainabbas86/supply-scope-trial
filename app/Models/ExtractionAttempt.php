<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractionOutcome;
use Database\Factories\ExtractionAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt at extracting one document — successful or not.
 *
 * This is the observability surface. Logs rotate and are awkward to query;
 * this table answers "why did this fail, how long did it take, and what did it
 * cost" from the database.
 *
 * @property string $id
 * @property string $document_id
 * @property int $attempt_no
 * @property string $model
 * @property string $prompt_version
 * @property ExtractionOutcome $outcome
 * @property string|null $error_class
 * @property string|null $error_message
 * @property int|null $http_status
 * @property int|null $latency_ms
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property array<string, mixed>|null $raw_response
 * @property Carbon|null $created_at
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

    /** @return BelongsTo<Document, $this> */
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
