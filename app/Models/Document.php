<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An uploaded label or specification sheet.
 *
 * @property string $id
 * @property DocumentStatus $status
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Casting to the enum means $document->status->isTerminal() works
            // and an unknown string from the database throws instead of
            // silently comparing false forever.
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------------

    /** Polymorphic: a User today, potentially a Tenant later. */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who physically uploaded it — may differ from the owner under tenancy. */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** The successful result, if there is one. */
    public function extraction(): HasOne
    {
        return $this->hasOne(Extraction::class);
    }

    /** Every attempt, successful or not. */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExtractionAttempt::class);
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Restrict to one owner.
     *
     * Deliberately an explicit scope rather than a global scope. A global scope
     * keyed on auth()->id() is a landmine in this application: the extraction
     * job runs in a worker container with NO authenticated user, so the scope
     * would silently match nothing and the job would fail to find its own
     * document — presenting as a queue bug. Authorisation on single records is
     * the policy's job; this is for list queries.
     */
    public function scopeForOwner(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    /** Queued or processing — what the UI polls for. */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DocumentStatus::Queued->value,
            DocumentStatus::Processing->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function hasFailed(): bool
    {
        return $this->status === DocumentStatus::Failed;
    }
}
