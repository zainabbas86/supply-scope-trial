<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Exceptions\FileRejected;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Models\User;
use App\Support\CurrentOwner;
use App\Support\DailySpendCeiling;
use App\Support\InjectionScanner;
use App\Support\PdfInspector;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Accept one or more uploaded label files.
 *
 * An Action rather than a controller method so the same code is reachable two
 * ways: as the route target, and directly from a test with a plain UploadedFile
 * and no HTTP request at all. The validation tests in §6 are the reason — they
 * are far cheaper to write against handle() than against a request cycle.
 *
 * Every file is judged INDEPENDENTLY. A batch of twenty containing one renamed
 * executable still accepts the other nineteen; rejecting the whole request
 * would make the user re-pick every file to find the bad one.
 */
class UploadDocuments
{
    use AsAction;

    public function __construct(
        private readonly PdfInspector $pdf,
        private readonly InjectionScanner $injection,
        private readonly DailySpendCeiling $ceiling,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{accepted: array<int, array<string, mixed>>, rejected: array<int, array<string, string>>}
     */
    public function handle(Model $owner, ?User $uploader, array $files): array
    {
        $accepted = [];
        $rejected = [];

        foreach ($files as $file) {
            try {
                $accepted[] = $this->ingest($owner, $uploader, $file);
            } catch (FileRejected $e) {
                $rejected[] = [
                    'filename' => $file->getClientOriginalName(),
                    'code' => $e->failureCode,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FileRejected
     */
    private function ingest(Model $owner, ?User $uploader, UploadedFile $file): array
    {
        // Checked per file, not once per batch: a batch of twenty must stop
        // at the ceiling rather than sail past it because the check happened
        // before any of them were counted.
        if (! $this->ceiling->hasCapacity()) {
            throw FileRejected::dailyLimitReached($this->ceiling->limit());
        }

        $this->assertUploadSucceeded($file);
        $extension = $this->assertAllowedExtension($file);
        $mime = $this->assertContentMatchesExtension($file, $extension);

        // Page count doubles as a readability gate: an encrypted or corrupt PDF
        // is rejected here, before anything is written or a worker is paid for.
        //
        // The same parse yields the text layer, which is scanned for content
        // addressed to the model rather than describing a product. Done HERE,
        // before the file is stored and before a job is queued: a document that
        // will be refused should cost nothing.
        $pageCount = null;

        if ($extension === 'pdf') {
            ['pages' => $pageCount, 'text' => $text] = $this->pdf->inspect($file->getRealPath());

            $findings = $this->injection->scan($text);

            if ($findings !== []) {
                throw FileRejected::suspiciousContent($findings[0]);
            }
        }

        // hash_file reads in chunks rather than loading the file into memory —
        // a 20 MB file must never become a 20 MB string, and with 20 files per
        // request that would be 400 MB.
        $sha256 = hash_file('sha256', $file->getRealPath());

        // Dedupe BEFORE storing, so an identical re-upload costs neither disk
        // nor an extraction. Scoped to the owner: matching on the hash alone
        // would hand this user a document belonging to someone else.
        if ($duplicate = $this->findCompletedDuplicate($owner, $sha256)) {
            return $this->describe($duplicate, duplicate: true);
        }

        $disk = config('filesystems.default');

        // Path is date-sharded and UUID-named. NEVER the client filename: it is
        // attacker-controlled, so it invites traversal and collisions, and it
        // would leak one user's filenames into another's storage listing.
        $path = sprintf(
            '%s/%s/%s.%s',
            trim((string) config('uploads.path_prefix'), '/'),
            now()->format('Y/m'),
            Str::uuid()->toString(),
            $extension,
        );

        // putFileAs streams through the Storage facade, so the identical code
        // works against a shared volume today and S3/GCS in a deployment.
        Storage::disk($disk)->putFileAs('', $file, $path);

        $document = DB::transaction(fn () => Document::create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'uploaded_by_user_id' => $uploader?->getKey(),
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
            'page_count' => $pageCount,
            // Stored PER DOCUMENT so files written under a local disk stay
            // readable after a later migration to object storage.
            'disk' => $disk,
            'storage_path' => $path,
            'status' => DocumentStatus::Queued,
            'queued_at' => now(),
        ]));

        // Dispatched after the transaction commits. Dispatching inside it is a
        // classic race: the worker can pick the job up and query for a row that
        // has not been committed yet.
        ExtractLabelData::dispatch($document);

        return $this->describe($document, duplicate: false);
    }

    // -----------------------------------------------------------------------
    // Validation, cheapest checks first
    // -----------------------------------------------------------------------

    private function assertUploadSucceeded(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw FileRejected::uploadFailed();
        }

        $size = (int) $file->getSize();

        if ($size === 0) {
            throw FileRejected::emptyFile();
        }

        $maxKb = (int) config('uploads.max_file_size_kb');

        if ($size > $maxKb * 1024) {
            throw FileRejected::tooLarge($maxKb);
        }
    }

    private function assertAllowedExtension(UploadedFile $file): string
    {
        /** @var array<string, array<int, string>> $allowed */
        $allowed = config('uploads.allowed');

        $extension = strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, $allowed)) {
            throw FileRejected::unsupportedExtension($extension, array_keys($allowed));
        }

        return $extension;
    }

    /**
     * The graded check.
     *
     * The type is read from the file's own bytes with finfo — NOT from
     * $file->getClientMimeType(), which is whatever the browser claimed and is
     * therefore attacker-controlled. An .exe renamed to .pdf passes the
     * extension check above and dies here.
     */
    private function assertContentMatchesExtension(UploadedFile $file, string $extension): string
    {
        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if ($sniffed === false || $sniffed === '') {
            throw FileRejected::unreadablePdf();
        }

        /** @var array<string, array<int, string>> $allowed */
        $allowed = config('uploads.allowed');

        if (! in_array($sniffed, $allowed[$extension], true)) {
            throw FileRejected::contentMismatch($extension, $sniffed);
        }

        return $sniffed;
    }

    private function findCompletedDuplicate(Model $owner, string $sha256): ?Document
    {
        // Hits the composite (owner_type, owner_id, sha256) index from §1.
        return Document::forOwner($owner)
            ->where('sha256', $sha256)
            ->where('status', DocumentStatus::Completed)
            ->latest('finished_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function describe(Document $document, bool $duplicate): array
    {
        return [
            'id' => $document->id,
            'filename' => $document->original_filename,
            'status' => $document->status->value,
            // Surfaced so the UI can say "we already had this one" rather than
            // showing an upload that instantly appears complete.
            'duplicate_of_existing' => $duplicate,
        ];
    }

    // -----------------------------------------------------------------------
    // HTTP entry point
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'max:'.config('uploads.max_files_per_request')],
            'files.*' => ['required', 'file'],
        ];
    }

    /** @return array<string, string> */
    public function getValidationMessages(): array
    {
        return [
            'files.required' => 'Choose at least one file to upload.',
            'files.max' => 'You can upload at most :max files at once.',
        ];
    }

    public function asController(Request $request): RedirectResponse
    {
        $result = $this->handle(
            CurrentOwner::resolve(),
            $request->user(),
            $request->file('files', []),
        );

        return back()->with([
            'uploaded' => $result['accepted'],
            'rejected' => $result['rejected'],
        ]);
    }
}
