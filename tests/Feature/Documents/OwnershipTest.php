<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocuments;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfBuilder;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
    $this->action = app(UploadDocuments::class);
});

// -----------------------------------------------------------------------------
// Dedupe — a cost optimisation that must not become a data leak
// -----------------------------------------------------------------------------

it('links a re-upload to the existing document without calling the model again', function () {
    $bytes = PdfBuilder::withPages(3);

    $first = $this->action->handle($this->alice, $this->alice, [uploadOf('spec.pdf', $bytes)]);
    Document::find($first['accepted'][0]['id'])->update([
        'status' => DocumentStatus::Completed,
        'finished_at' => now(),
    ]);

    $second = $this->action->handle($this->alice, $this->alice, [uploadOf('spec-copy.pdf', $bytes)]);

    expect($second['accepted'][0]['duplicate_of_existing'])->toBeTrue()
        ->and($second['accepted'][0]['id'])->toBe($first['accepted'][0]['id'])
        ->and(Document::count())->toBe(1);

    // Only the first upload cost an extraction.
    ExtractLabelData::assertPushed(1);
});

it('never dedupes across owners', function () {
    // THE security-relevant one. Matching on the hash alone would hand Bob a
    // document — and an extraction — belonging to Alice. The index is scoped
    // to the owner precisely so the cheap query is also the correct one.
    $bytes = PdfBuilder::withPages(3);

    $alices = $this->action->handle($this->alice, $this->alice, [uploadOf('spec.pdf', $bytes)]);
    Document::find($alices['accepted'][0]['id'])->update([
        'status' => DocumentStatus::Completed,
        'finished_at' => now(),
    ]);

    $bobs = $this->action->handle($this->bob, $this->bob, [uploadOf('spec.pdf', $bytes)]);

    expect($bobs['accepted'][0]['duplicate_of_existing'])->toBeFalse()
        ->and($bobs['accepted'][0]['id'])->not->toBe($alices['accepted'][0]['id'])
        ->and(Document::count())->toBe(2);

    ExtractLabelData::assertPushed(2);
});

it('does not dedupe against a document that has not completed', function () {
    // An in-flight or failed document has no result to reuse. Linking to one
    // would leave the user staring at a document that never finishes.
    $bytes = PdfBuilder::withPages(3);

    $this->action->handle($this->alice, $this->alice, [uploadOf('spec.pdf', $bytes)]);
    $second = $this->action->handle($this->alice, $this->alice, [uploadOf('spec.pdf', $bytes)]);

    expect($second['accepted'][0]['duplicate_of_existing'])->toBeFalse()
        ->and(Document::count())->toBe(2);
});

// -----------------------------------------------------------------------------
// Scoping
// -----------------------------------------------------------------------------

it('scopes queries to a single owner', function () {
    Document::factory()->ownedBy($this->alice)->count(3)->create();
    Document::factory()->ownedBy($this->bob)->count(2)->create();

    expect(Document::forOwner($this->alice)->count())->toBe(3)
        ->and(Document::forOwner($this->bob)->count())->toBe(2)
        // Unscoped, everything is visible — which is exactly why the scope is
        // applied explicitly at every call site rather than hoped for.
        ->and(Document::count())->toBe(5);
});

it('records who uploaded a document separately from who owns it', function () {
    // Identical today; they diverge the moment an organisation owns a document
    // that a person uploaded. Capturing it now avoids backfilling attribution
    // that was never recorded.
    $this->action->handle($this->alice, $this->alice, [pdfUpload()]);

    $document = Document::sole();

    expect($document->owner_type)->toBe($this->alice->getMorphClass())
        ->and((string) $document->owner_id)->toBe((string) $this->alice->getKey())
        ->and($document->uploaded_by_user_id)->toBe($this->alice->getKey())
        ->and($document->owner)->toBeInstanceOf(User::class);
});

it('stores ownership polymorphically so a tenant model can replace the user later', function () {
    $document = Document::factory()->ownedBy($this->alice)->create();

    // The schema does not name User anywhere — swapping in a Tenant is a data
    // migration, not a rewrite.
    expect($document->owner_type)->toBe(User::class)
        ->and($document->getAttributes())->toHaveKeys(['owner_type', 'owner_id']);
});

// -----------------------------------------------------------------------------
// Authorisation on a single record
// -----------------------------------------------------------------------------

it('hides another owner document behind a 404 rather than a 403', function () {
    $alices = Document::factory()->ownedBy($this->alice)->create();

    // A 403 confirms the document exists and merely is not yours — an oracle.
    // "Not found" reveals nothing either way.
    expect($this->bob->can('view', $alices))->toBeFalse()
        ->and($this->alice->can('view', $alices))->toBeTrue();

    $response = Gate::forUser($this->bob)->inspect('view', $alices);
    expect($response->status())->toBe(404);
});

it('allows a retry only on a failed document the caller owns', function () {
    $failed = Document::factory()->ownedBy($this->alice)->failed()->create();
    $completed = Document::factory()->ownedBy($this->alice)->completed()->create();

    // Re-dispatching spends money, so retry is deliberately narrower than view.
    expect($this->alice->can('retry', $failed))->toBeTrue()
        ->and($this->alice->can('retry', $completed))->toBeFalse()
        ->and($this->bob->can('retry', $failed))->toBeFalse();
});
