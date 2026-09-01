<?php

use App\Models\User;
use App\Services\Extraction\FakeLabelExtractor;
use App\Services\Extraction\LabelExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\PdfBuilder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full application and each runs inside a transaction
| that is rolled back afterwards, so tests never leak state into each other.
|
| Unit tests deliberately stay on plain PHPUnit: they should not need a booted
| framework, and keeping them framework-free makes it obvious when a "unit"
| test has quietly grown a database dependency.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Bind a scripted fake extractor for the duration of a test.
 *
 * NOTHING in the suite is allowed to reach the network. This is the seam that
 * guarantees it: the job and the upload path depend on the LabelExtractor
 * interface, never on the OpenAI client. phpunit.xml also blanks
 * OPENAI_API_KEY, so a test that somehow escaped this would fail loudly on a
 * 401 rather than quietly spending money.
 *
 * @param  list<Throwable|string>  $script
 */
function fakeExtractor(array $script = ['success']): FakeLabelExtractor
{
    $fake = new FakeLabelExtractor;
    $fake->setScript($script);
    app()->instance(LabelExtractor::class, $fake);

    return $fake;
}

/** A signed-in user, since every application route is behind auth. */
function actingAsUser(?User $user = null): User
{
    $user ??= User::factory()->create();
    test()->actingAs($user);

    return $user;
}

/** A genuinely valid multi-page PDF as an uploaded file. */
function pdfUpload(string $name = 'spec-sheet.pdf', int $pages = 3): UploadedFile
{
    return uploadOf($name, PdfBuilder::withPages($pages));
}

function pngUpload(string $name = 'label.png'): UploadedFile
{
    return uploadOf($name, PdfBuilder::png());
}

/** Wrap arbitrary bytes as an upload with a chosen client filename. */
function uploadOf(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($path, $bytes);

    // test: true — skips the is_uploaded_file() check, which only passes for a
    // genuine multipart request.
    return new UploadedFile($path, $name, null, null, true);
}
