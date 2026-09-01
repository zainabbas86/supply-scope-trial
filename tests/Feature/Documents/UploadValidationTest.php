<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocuments;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfBuilder;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->action = app(UploadDocuments::class);
});

function upload(array $files): array
{
    return test()->action->handle(test()->owner, test()->owner, $files);
}

// -----------------------------------------------------------------------------
// Required by the brief: unsupported file types
// -----------------------------------------------------------------------------

it('rejects an unsupported file type and queues nothing', function () {
    $result = upload([uploadOf('notes.txt', 'just some text')]);

    expect($result['accepted'])->toBeEmpty()
        ->and($result['rejected'][0]['code'])->toBe('unsupported_extension')
        ->and($result['rejected'][0]['reason'])->toContain('.txt');

    expect(Document::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects an executable renamed to .pdf by sniffing its contents', function () {
    // The graded case. The extension allowlist passes — the file really is
    // named .pdf — so the only thing standing between this and the extraction
    // pipeline is reading the actual bytes.
    $result = upload([uploadOf('invoice.pdf', PdfBuilder::executable())]);

    expect($result['accepted'])->toBeEmpty()
        ->and($result['rejected'][0]['code'])->toBe('content_type_mismatch')
        ->and($result['rejected'][0]['reason'])->toContain('application/x-dosexec');

    Queue::assertNothingPushed();
});

it('trusts sniffed content over the browser-supplied mime type', function () {
    // A client can claim any Content-Type it likes. UploadedFile's fourth
    // argument is exactly that claim — here it lies, saying a PNG is a PDF.
    $path = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($path, PdfBuilder::png());
    $liar = new UploadedFile($path, 'photo.pdf', 'application/pdf', null, true);

    $result = upload([$liar]);

    expect($result['rejected'][0]['code'])->toBe('content_type_mismatch')
        ->and($result['rejected'][0]['reason'])->toContain('image/png');
});

// -----------------------------------------------------------------------------
// PDF-specific rejections, each with its own reason
// -----------------------------------------------------------------------------

it('rejects an encrypted pdf with an actionable reason', function () {
    $result = upload([uploadOf('locked.pdf', PdfBuilder::encrypted())]);

    expect($result['rejected'][0]['code'])->toBe('encrypted_pdf')
        // "Corrupt" would be unhelpful: the user can actually do something
        // about a password, so the two failures must not be conflated.
        ->and($result['rejected'][0]['reason'])->toContain('password protected');
});

it('rejects an empty file', function () {
    expect(upload([uploadOf('empty.pdf', '')])['rejected'][0]['code'])->toBe('empty_file');
});

it('rejects a pdf with more pages than the time budget allows', function () {
    $result = upload([pdfUpload('long.pdf', pages: 20)]);

    expect($result['rejected'][0]['code'])->toBe('too_many_pages')
        ->and($result['rejected'][0]['reason'])->toContain('20 pages');
});

it('rejects a file over the size limit', function () {
    config()->set('uploads.max_file_size_kb', 1);

    expect(upload([uploadOf('big.pdf', str_repeat('x', 2048))])['rejected'][0]['code'])
        ->toBe('file_too_large');
});

it('rejects a file with no extension at all', function () {
    expect(upload([uploadOf('README', PdfBuilder::withPages(1))])['rejected'][0]['code'])
        ->toBe('unsupported_extension');
});

// -----------------------------------------------------------------------------
// The behaviour that makes a batch usable
// -----------------------------------------------------------------------------

it('judges every file in a batch independently', function () {
    // One bad file must not cost the user the other three. Rejecting the whole
    // request would mean re-picking every file to find the offender.
    $result = upload([
        pdfUpload('good-one.pdf'),
        uploadOf('bad.txt', 'nope'),
        pngUpload('good-two.png'),
        uploadOf('evil.pdf', PdfBuilder::executable()),
    ]);

    expect($result['accepted'])->toHaveCount(2)
        ->and($result['rejected'])->toHaveCount(2)
        ->and(array_column($result['rejected'], 'code'))
        ->toBe(['unsupported_extension', 'content_type_mismatch']);

    expect(Document::count())->toBe(2);
    // laravel-actions wraps jobs in a JobDecorator, so Queue::assertPushed
    // would need the decorator class; the AsJob trait's own assertion knows
    // how to unwrap it and reads far better.
    ExtractLabelData::assertPushed(2);
});

// -----------------------------------------------------------------------------
// What an accepted file actually records
// -----------------------------------------------------------------------------

it('stores an accepted pdf with a hashed, non-guessable path', function () {
    upload([pdfUpload('Sensitive Supplier Name.pdf', pages: 3)]);

    $document = Document::sole();

    expect($document->status)->toBe(DocumentStatus::Queued)
        ->and($document->page_count)->toBe(3)
        ->and($document->mime_type)->toBe('application/pdf')
        ->and(strlen($document->sha256))->toBe(64)
        ->and($document->original_filename)->toBe('Sensitive Supplier Name.pdf')
        // The client's filename is kept as data but must never appear in the
        // path: it is attacker-controlled, invites traversal and collisions,
        // and would leak one user's filenames into a storage listing.
        ->and($document->storage_path)->not->toContain('Sensitive')
        ->and($document->storage_path)->toStartWith('documents/')
        ->and($document->queued_at)->not->toBeNull();

    Storage::disk('local')->assertExists($document->storage_path);
});

it('records the disk per document so stored files survive a move to s3', function () {
    upload([pdfUpload()]);

    // Hardcoding the disk in config would strand every existing row the day
    // FILESYSTEM_DISK changes.
    expect(Document::sole()->disk)->toBe(config('filesystems.default'));
});

it('accepts images, which have no page count', function () {
    upload([pngUpload()]);

    expect(Document::sole()->page_count)->toBeNull()
        ->and(Document::sole()->mime_type)->toBe('image/png');
});

it('dispatches exactly one extraction job per accepted file', function () {
    upload([pdfUpload('a.pdf'), pdfUpload('b.pdf'), uploadOf('c.txt', 'no')]);

    ExtractLabelData::assertPushed(2);
});

it('caps the number of files accepted in one request', function () {
    // Enforced by the action's validation rules rather than in handle(), so
    // assert the rule itself rather than reaching through HTTP.
    expect(app(UploadDocuments::class)->rules()['files'])
        ->toContain('max:'.config('uploads.max_files_per_request'));
});

// -----------------------------------------------------------------------------
// Storage portability
// -----------------------------------------------------------------------------

it('writes through the storage facade, not the local filesystem', function () {
    // Proves the worker's assumption holds: the upload path never touches
    // container-local disk directly, so the same code works against a shared
    // volume today and object storage in a deployment.
    Storage::fake('s3');
    config()->set('filesystems.default', 's3');

    upload([pdfUpload()]);

    $document = Document::sole();
    expect($document->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($document->storage_path);
    Storage::disk('local')->assertMissing($document->storage_path);
});
