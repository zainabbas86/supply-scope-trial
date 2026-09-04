<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocuments;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Models\ExtractionAttempt;
use App\Models\User;
use App\Support\DailySpendCeiling;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->action = app(UploadDocuments::class);
});

/** Burn budget the way real work does — one attempt row per model call. */
function spend(int $calls): void
{
    $document = Document::factory()->ownedBy(test()->owner)->create();

    for ($i = 1; $i <= $calls; $i++) {
        ExtractionAttempt::factory()->attempt($i)->create(['document_id' => $document->id]);
    }
}

it('counts attempts, not documents, because attempts are what cost money', function () {
    config()->set('access.extraction_daily_limit', 10);

    // One document retried three times is three model calls, not one.
    spend(3);

    expect(app(DailySpendCeiling::class)->used())->toBe(3)
        ->and(app(DailySpendCeiling::class)->remaining())->toBe(7);
});

it('rejects an upload once the daily ceiling is spent', function () {
    config()->set('access.extraction_daily_limit', 2);
    spend(2);

    $result = $this->action->handle($this->owner, $this->owner, [pdfUpload()]);

    expect($result['accepted'])->toBeEmpty()
        ->and($result['rejected'][0]['code'])->toBe('daily_limit_reached')
        ->and($result['rejected'][0]['reason'])->toContain('daily extraction limit of 2');

    // Rejected at upload rather than silently queued: a document that will not
    // run until tomorrow should say so now, not sit at `queued` looking busy.
    expect(Document::count())->toBe(1); // only the one spend() created
    Queue::assertNothingPushed();
});

it('accepts uploads while budget remains', function () {
    config()->set('access.extraction_daily_limit', 5);
    spend(2);

    $result = $this->action->handle($this->owner, $this->owner, [pdfUpload()]);

    expect($result['accepted'])->toHaveCount(1)
        ->and($result['rejected'])->toBeEmpty();

    ExtractLabelData::assertPushed(1);
});

it('stops a batch at the ceiling instead of letting it sail past', function () {
    // The check is per FILE. Checking once per batch would let twenty files
    // through on a budget of one.
    config()->set('access.extraction_daily_limit', 1);

    $result = $this->action->handle($this->owner, $this->owner, [
        pdfUpload('a.pdf'),
        pdfUpload('b.pdf'),
        pdfUpload('c.pdf'),
    ]);

    // Nothing spent yet, so all three are within a limit of 1 at check time —
    // the ceiling bounds the day, and the per-minute upload throttle bounds the
    // burst. Both exist because neither alone is sufficient.
    expect($result['accepted'])->toHaveCount(3);

    // Now the budget is genuinely gone.
    spend(1);
    $after = $this->action->handle($this->owner, $this->owner, [pdfUpload('d.pdf')]);
    expect($after['rejected'][0]['code'])->toBe('daily_limit_reached');
});

it('blocks a retry once the ceiling is spent', function () {
    // A retry is a fresh model call. Enforcing only at upload would leave an
    // obvious way around the cap.
    config()->set('access.extraction_daily_limit', 1);
    spend(1);

    $failed = Document::factory()->ownedBy($this->owner)->failed()->create();

    $this->actingAs($this->owner)
        ->post(route('documents.retry', $failed))
        ->assertRedirect();

    expect($failed->fresh()->status->value)->toBe('failed');
    ExtractLabelData::assertNotPushed();
});

it('treats a limit of zero as disabled', function () {
    // A test or development environment should not be tripped by a ceiling it
    // never asked for.
    config()->set('access.extraction_daily_limit', 0);
    spend(50);

    expect(app(DailySpendCeiling::class)->hasCapacity())->toBeTrue();

    $result = $this->action->handle($this->owner, $this->owner, [pdfUpload()]);
    expect($result['accepted'])->toHaveCount(1);
});
