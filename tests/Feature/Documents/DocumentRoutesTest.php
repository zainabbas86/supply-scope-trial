<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| HTTP-level tests
|--------------------------------------------------------------------------
|
| The rest of the suite calls actions and jobs directly, which tests the logic
| and skips the wiring. Two runtime failures got through exactly that gap:
|
|   - ExtractLabelData::handle() took a dependency laravel-actions never passes
|   - DocumentController called $this->authorize(), which Laravel 11+ removed
|     from the base Controller
|
| Both were invisible to a direct call and immediate over HTTP. These tests
| exist to close that gap.
|
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->actingAs($this->owner);
});

it('renders the index with the upload limits the server enforces', function () {
    $this->get(route('documents.index'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Documents/Index')
            ->where('maxFiles', config('uploads.max_files_per_request'))
            // Sent from config so the dropzone's courtesy check and the
            // server's real rule cannot disagree.
            ->where('maxFileSizeMb', 20)
            ->has('documents', 0)
    );
});

it('lists only the caller documents', function () {
    Document::factory()->ownedBy($this->owner)->count(2)->create();
    Document::factory()->ownedBy($this->other)->count(3)->create();

    $this->get(route('documents.index'))->assertInertia(
        fn (AssertableInertia $page) => $page->has('documents', 2)
    );
});

it('serves the polling endpoint as json and reports whether work is in flight', function () {
    Document::factory()->ownedBy($this->owner)->create(['status' => DocumentStatus::Queued]);

    $this->getJson(route('documents.status'))
        ->assertOk()
        ->assertJsonPath('processing', true)
        ->assertJsonCount(1, 'documents');
});

it('reports nothing in flight once everything is terminal', function () {
    Document::factory()->ownedBy($this->owner)->completed()->create();
    Document::factory()->ownedBy($this->owner)->failed()->create();

    // The client stops polling on this flag, so a wrong answer here means
    // either a stuck spinner or a list that never updates.
    $this->getJson(route('documents.status'))->assertJsonPath('processing', false);
});

it('shows a document with its extraction and attempts', function () {
    $document = Document::factory()->ownedBy($this->owner)->completed()->create();
    Extraction::factory()->create(['document_id' => $document->id]);

    $this->get(route('documents.show', $document))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Documents/Show')
                ->where('document.id', $document->id)
                ->has('document.extraction')
                // attempts_log, not attempts: `documents.attempts` is an
                // integer column and the relation once shared its name.
                ->has('document.attempts_log')
        );
});

it('returns 404, not 403, for another owner document', function () {
    $theirs = Document::factory()->ownedBy($this->other)->create();

    // A 403 confirms the document exists and merely is not yours — an oracle
    // an attacker can enumerate against.
    $this->get(route('documents.show', $theirs))->assertNotFound();
});

it('re-dispatches a failed document and clears the stale failure', function () {
    Queue::fake();
    $document = Document::factory()->ownedBy($this->owner)->failed()->create();

    $this->post(route('documents.retry', $document))->assertRedirect();

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Queued)
        // A stale reason beside a queued document reads as though it failed
        // again the instant it was retried.
        ->and($document->failure_reason)->toBeNull()
        ->and($document->failure_code)->toBeNull()
        ->and($document->finished_at)->toBeNull();

    ExtractLabelData::assertPushed(1);
});

it('refuses to retry a completed document', function () {
    Queue::fake();
    // Re-running a completed extraction pays for something already extracted.
    $document = Document::factory()->ownedBy($this->owner)->completed()->create();

    $this->post(route('documents.retry', $document))->assertForbidden();

    ExtractLabelData::assertNotPushed();
});

it('refuses to retry another owner document', function () {
    Queue::fake();
    $theirs = Document::factory()->ownedBy($this->other)->failed()->create();

    $this->post(route('documents.retry', $theirs))->assertNotFound();

    ExtractLabelData::assertNotPushed();
});

// -----------------------------------------------------------------------------
// Downloading the original
// -----------------------------------------------------------------------------
//
// The extraction is a claim ABOUT a file. Serving the file back is what lets
// anyone check the claim - and it is also the one place the app hands user
// bytes to a browser, so the headers matter as much as the ownership check.

it('streams the original file back to its owner', function () {
    Storage::fake('local');
    $document = Document::factory()->for($this->owner, 'owner')->create([
        'disk' => 'local',
        'storage_path' => 'documents/2026/09/abc.pdf',
        'original_filename' => 'coldwater bay.pdf',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($document->storage_path, '%PDF-1.4 fake');

    $response = $this->get(route('documents.download', $document));

    $response->assertOk();
    $response->assertHeader('x-content-type-options', 'nosniff');

    // attachment, never inline. A PDF can carry JavaScript, and rendering one
    // inline would run it on this origin.
    expect($response->headers->get('content-disposition'))->toContain('attachment');
});

it('refuses to download a document belonging to someone else', function () {
    $theirs = Document::factory()->for($this->other, 'owner')->create();

    // 404, not 403: a 403 confirms the document exists.
    $this->get(route('documents.download', $theirs))->assertNotFound();
});

it('404s when the row outlives the file', function () {
    Storage::fake('local');
    $document = Document::factory()->for($this->owner, 'owner')->create([
        'disk' => 'local',
        'storage_path' => 'documents/2026/09/missing.pdf',
    ]);

    // A restored database without its volume. The document exists; the bytes
    // do not, and pretending otherwise gives a 500 instead of an answer.
    $this->get(route('documents.download', $document))->assertNotFound();
});
