<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Support\CurrentOwner;
use App\Support\DailySpendCeiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Documents/Index', [
            'documents' => $this->ownedDocuments(),
            // Sent from config rather than hardcoded in the component, so the
            // client-side courtesy check and the server's real rule can never
            // disagree about what the limit is.
            'maxFiles' => (int) config('uploads.max_files_per_request'),
            'maxFileSizeMb' => (int) round(config('uploads.max_file_size_kb') / 1024),
        ]);
    }

    /**
     * Polled by the UI while anything is in flight.
     *
     * Returns the same summary shape as index() so the client can swap rows in
     * without a second mapping — two shapes for the same rows is how a list and
     * its live updates quietly drift apart.
     *
     * Deliberately lightweight: no extraction payloads, just the fields that
     * change. It is hit every 2.5s per open tab, which is why it has its own
     * generous rate limit rather than sharing the default.
     */
    public function status(): JsonResponse
    {
        $documents = $this->ownedDocuments();

        return response()->json([
            'documents' => $documents,
            // The client stops polling on this rather than re-deriving it from
            // the rows, so the server owns the decision.
            'processing' => $documents->contains(
                fn (array $d) => in_array($d['status'], ['queued', 'processing'], true)
            ),
        ]);
    }

    /**
     * Stream the original uploaded file back to its owner.
     *
     * Nothing deletes uploads, so the bytes behind every row are still there.
     * The value of this is that the extraction is a claim ABOUT a document —
     * "allergens: milk, soy" — and the only way to check a claim is against
     * the source. Without this the UI asks you to take the model's word.
     *
     * Three things make serving user-uploaded bytes safe here:
     *
     *  1. Ownership is checked by the same gate as `show`, so a foreign id is
     *     a 404 rather than a 403 — a 403 would confirm the document exists.
     *  2. `Content-Disposition: attachment` means the browser saves the file
     *     instead of rendering it. A PDF can contain JavaScript, and rendering
     *     one inline on this origin would run it there.
     *  3. `X-Content-Type-Options: nosniff` stops the browser second-guessing
     *     the type and rendering it as something executable anyway.
     *
     * The Content-Type comes from `mime_type`, which was SNIFFED FROM THE
     * FILE'S OWN BYTES at upload (see UploadDocuments), never from the browser
     * or the extension. Echoing back a client-supplied type would hand an
     * attacker the ability to choose how their file is interpreted.
     */
    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        $disk = Storage::disk($document->disk);

        // The row can outlive the file: a restored database, a volume that was
        // not restored with it. 404 is honest — the document exists, the bytes
        // do not.
        abort_unless($disk->exists($document->storage_path), 404);

        return $disk->download(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => $document->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function show(Document $document): Response
    {
        // Gate::authorize, not $this->authorize: Laravel 11+ ships a bare base
        // Controller without the AuthorizesRequests trait, so the familiar
        // $this->authorize() is simply undefined and fails at runtime.
        //
        // 404 rather than 403 for someone else's document — a 403 confirms it
        // exists, which is an oracle.
        Gate::authorize('view', $document);

        $document->load([
            'extraction',
            'extractionAttempts' => fn ($q) => $q->orderBy('attempt_no'),
        ]);

        return Inertia::render('Documents/Show', [
            'document' => [
                ...$this->summarise($document),
                'sizeBytes' => $document->size_bytes,
                'mimeType' => $document->mime_type,
                'sha256' => $document->sha256,
                'extraction' => $document->extraction ? [
                    'productName' => $document->extraction->product_name,
                    'productNamePage' => $document->extraction->product_name_page,
                    'brand' => $document->extraction->brand,
                    'brandPage' => $document->extraction->brand_page,
                    'productType' => $document->extraction->product_type->value,
                    'ingredients' => $document->extraction->ingredients,
                    'allergens' => $document->extraction->allergens,
                    'netWeight' => $document->extraction->net_weight,
                    'warnings' => $document->extraction->warnings ?? [],
                    'schemaVersion' => $document->extraction->schema_version,
                    'raw' => $document->extraction->raw_response,
                ] : null,
                // Named attempts_log, not attempts: `documents.attempts` is an
                // integer count column, and the relation shares its name. Two
                // different things called `attempts` in one payload is a bug
                // waiting to be written.
                'attempts_log' => $document->extractionAttempts->map(fn ($a) => [
                    'number' => $a->attempt_no,
                    'outcome' => $a->outcome->value,
                    'model' => $a->model,
                    'httpStatus' => $a->http_status,
                    'latencyMs' => $a->latency_ms,
                    'tokens' => $a->totalTokens(),
                    'errorMessage' => $a->error_message,
                    'at' => $a->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * Re-dispatch a failed extraction.
     *
     * Guarded by the policy to FAILED documents only: re-running a completed
     * one would pay for a second extraction of something already extracted.
     */
    public function retry(Document $document): RedirectResponse
    {
        Gate::authorize('retry', $document);

        // A retry is a fresh model call, so it spends from the same budget.
        // Enforcing only at upload would leave an obvious way around the cap.
        $ceiling = app(DailySpendCeiling::class);

        if (! $ceiling->hasCapacity()) {
            return back()->with(
                'error',
                "The daily extraction limit of {$ceiling->limit()} has been reached. Please try again tomorrow."
            );
        }

        // Back to `queued` so the job's atomic claim can take it. Resetting
        // the failure fields matters too — a stale reason next to a queued
        // document reads as though it failed again.
        $document->update([
            'status' => DocumentStatus::Queued,
            'failure_code' => null,
            'failure_reason' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ]);

        ExtractLabelData::dispatch($document);

        return back()->with('success', 'Retrying extraction for '.$document->original_filename.'.');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function ownedDocuments()
    {
        return Document::forOwner(CurrentOwner::resolve())
            ->with('extraction:id,document_id,product_name,brand')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Document $d) => $this->summarise($d));
    }

    /** @return array<string, mixed> */
    private function summarise(Document $document): array
    {
        return [
            'id' => $document->id,
            'filename' => $document->original_filename,
            'status' => $document->status->value,
            'statusLabel' => $document->status->label(),
            'pageCount' => $document->page_count,
            'attempts' => $document->attempts_count ?? $document->getAttribute('attempts'),
            'failureCode' => $document->failure_code,
            'failureReason' => $document->failure_reason,
            'productName' => $document->extraction?->product_name,
            'brand' => $document->extraction?->brand,
            'createdAt' => $document->created_at?->toIso8601String(),
            'finishedAt' => $document->finished_at?->toIso8601String(),
        ];
    }
}
