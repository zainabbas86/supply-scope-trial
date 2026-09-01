<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\ExtractionOutcome;
use App\Exceptions\Extraction\RetryableExtractionException;
use App\Exceptions\Extraction\TerminalExtractionException;
use App\Jobs\ExtractLabelData;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\ExtractionAttempt;
use App\Models\User;
use App\Services\Extraction\LabelExtractor;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
});

/** A queued document whose file genuinely exists on the fake disk. */
function queuedDocument(array $attributes = []): Document
{
    $document = Document::factory()
        ->ownedBy(test()->owner)
        ->create(['disk' => 'local', 'page_count' => 3, ...$attributes]);

    Storage::disk('local')->put($document->storage_path, 'pdf-bytes');

    return $document;
}

/**
 * Run the job the way a worker would.
 *
 * Retryable failures are rethrown by design — the queue owns the backoff — so
 * a test that wants to simulate the next attempt catches and calls again.
 */
function runJob(Document $document): ?Throwable
{
    try {
        app(ExtractLabelData::class)->handle($document, app(LabelExtractor::class));

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

// -----------------------------------------------------------------------------
// Happy path
// -----------------------------------------------------------------------------

it('extracts, persists and completes a document', function () {
    fakeExtractor(['success']);
    $document = queuedDocument();

    expect(runJob($document))->toBeNull();

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Completed)
        ->and($document->attempts)->toBe(1)
        ->and($document->started_at)->not->toBeNull()
        ->and($document->finished_at)->not->toBeNull()
        ->and($document->failure_code)->toBeNull()
        ->and($document->extraction)->not->toBeNull();
});

it('records latency and token usage for every attempt', function () {
    fakeExtractor(['success']);
    $document = queuedDocument();
    runJob($document);

    $attempt = ExtractionAttempt::sole();

    // Without these, "how long does extraction take" and "what would 50,000
    // uploads cost" are unanswerable after the fact.
    expect($attempt->outcome)->toBe(ExtractionOutcome::Success)
        ->and($attempt->latency_ms)->toBeGreaterThan(0)
        ->and($attempt->input_tokens)->toBeGreaterThan(0)
        ->and($attempt->totalTokens())->toBeGreaterThan(0)
        ->and($attempt->prompt_version)->toBe(config('extraction.prompt_version'));
});

// -----------------------------------------------------------------------------
// Required by the brief: retries
// -----------------------------------------------------------------------------

it('retries a rate limit and succeeds on a later attempt', function () {
    fakeExtractor([
        RetryableExtractionException::rateLimited(),
        'success',
    ]);
    $document = queuedDocument();

    // Attempt 1 fails and is rethrown so the queue will schedule a retry.
    expect(runJob($document))->toBeInstanceOf(RetryableExtractionException::class);

    // Critically, it is back in `queued` — left in `processing`, the atomic
    // claim would reject every retry and the job would silently stop trying
    // while still looking busy.
    expect($document->fresh()->status)->toBe(DocumentStatus::Queued);

    expect(runJob($document))->toBeNull();

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Completed)
        ->and($document->attempts)->toBe(2);

    expect(ExtractionAttempt::orderBy('attempt_no')->pluck('outcome')->all())
        ->toBe([ExtractionOutcome::RetryableError, ExtractionOutcome::Success]);
});

it('fails a 400 immediately without burning the retry budget', function () {
    // The wasted-retry case. A 400 will be a 400 on all three attempts, so
    // retrying holds a worker and delays the user's answer for nothing.
    $fake = fakeExtractor([TerminalExtractionException::badRequest('unsupported input')]);
    $document = queuedDocument();

    expect(runJob($document))->toBeNull(); // handled, NOT rethrown

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->attempts)->toBe(1)
        ->and($document->attempts)->toBeLessThan(config('extraction.max_tries'))
        ->and($fake->callCount())->toBe(1)
        ->and($document->failure_code)->toBe('invalid_request');
});

it('fails with a readable reason once the retries are exhausted', function () {
    $document = queuedDocument();

    app(ExtractLabelData::class)->jobFailed(
        RetryableExtractionException::timedOut(90),
        $document,
    );

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Failed)
        // The in-flight wording promises an automatic retry. Reusing it here
        // would leave the document promising something that will never happen.
        ->and($document->failure_reason)->toContain('after 3 attempts')
        ->and($document->failure_reason)->not->toContain('will be retried');
});

// -----------------------------------------------------------------------------
// Required by the brief: malformed model output
// -----------------------------------------------------------------------------

it('fails with a distinct reason for each kind of bad model output', function (string $script, string $code) {
    fakeExtractor([$script]);
    $document = queuedDocument();

    runJob($document);
    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure_code)->toBe($code)
        // A machine code for branching, a sentence for the person waiting.
        ->and($document->failure_reason)->not->toBeEmpty()
        ->and($document->failure_reason)->not->toContain('Exception');

    // Every failure leaves an audit row, so "why did this fail?" is answerable
    // from the database rather than from logs that have already rotated.
    expect(ExtractionAttempt::sole()->outcome->isFailure())->toBeTrue();
})->with([
    'model refusal' => ['refusal', 'model_refusal'],
    'schema-invalid output' => ['invalid', 'invalid_output'],
]);

it('fails terminally when the stored file has vanished', function () {
    fakeExtractor(['success']);
    $document = queuedDocument();
    Storage::disk('local')->delete($document->storage_path);

    runJob($document);

    expect($document->fresh()->failure_code)->toBe('file_missing');
});

// -----------------------------------------------------------------------------
// Idempotency and concurrency
// -----------------------------------------------------------------------------

it('produces exactly one extraction when the same job runs twice', function () {
    // Redis redelivers whenever a worker dies mid-job, so this is an ordinary
    // event rather than an edge case.
    $fake = fakeExtractor(['success']);
    $document = queuedDocument();

    runJob($document);
    runJob($document);

    expect(Extraction::where('document_id', $document->id)->count())->toBe(1)
        ->and($fake->callCount())->toBe(1)   // not re-billed
        ->and($document->fresh()->attempts)->toBe(1);
});

it('exits without touching a document another worker already claimed', function () {
    $fake = fakeExtractor(['success']);
    $document = queuedDocument(['status' => DocumentStatus::Processing]);

    runJob($document);

    // The conditional UPDATE ... WHERE status = 'queued' is the real guard:
    // two workers can both read `queued`, but only one can win the write.
    expect($document->fresh()->status)->toBe(DocumentStatus::Processing)
        ->and($fake->callCount())->toBe(0)
        ->and(Extraction::count())->toBe(0);
});

it('skips a document that has already finished', function () {
    $fake = fakeExtractor(['success']);
    $document = queuedDocument(['status' => DocumentStatus::Completed]);

    runJob($document);

    expect($fake->callCount())->toBe(0)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Completed);
});

// -----------------------------------------------------------------------------
// Data integrity
// -----------------------------------------------------------------------------

it('round-trips non-ascii text through postgres unchanged', function () {
    // The real model output contains en-dashes. A broken encoding anywhere in
    // the chain corrupts them silently and is miserable to trace later.
    fakeExtractor(['success']);
    $document = queuedDocument();
    runJob($document);

    $name = $document->fresh()->extraction->product_name;

    expect($name)->toBe('Coldwater Bay – Fish – Fillets – Battered')
        ->and($name)->toContain('–')
        ->and(mb_check_encoding($name, 'UTF-8'))->toBeTrue();
});

it('preserves the honest allergen answer end to end', function () {
    // The safety-critical case: the statement was left incomplete, so the
    // allergens are reported as DERIVED from the ingredients, never as a
    // declaration the manufacturer did not make.
    fakeExtractor(['success']);
    $document = queuedDocument();
    runJob($document);

    $extraction = $document->fresh()->extraction;

    expect($extraction->allergenStatementStatus())->toBe('not_completed')
        ->and($extraction->hasIncompleteAllergenStatement())->toBeTrue()
        ->and($extraction->declaredAllergens())->toBe([])
        ->and($extraction->derivedAllergens())->toBe(['Fish', 'Wheat', 'Milk'])
        ->and($extraction->warnings)->not->toBeEmpty();
});

it('keeps the basis that makes a net weight meaningful', function () {
    fakeExtractor(['success']);
    $document = queuedDocument();
    runJob($document);

    $extraction = $document->fresh()->extraction;

    // One page offers a portion, a pack and a carton weight. "800g" alone
    // throws away which of the three it is.
    expect($extraction->net_weight['value'])->toBe(800)
        ->and($extraction->net_weight['unit'])->toBe('g')
        ->and($extraction->netWeightBasis())->toBe('per_pack');
});

it('reports non-food products without inventing allergens', function () {
    fakeExtractor(['non_food']);
    $document = queuedDocument();
    runJob($document);

    $extraction = $document->fresh()->extraction;

    expect($extraction->product_type->value)->toBe('non_food')
        ->and($extraction->allergenStatementStatus())->toBe('not_applicable')
        ->and($extraction->declaredAllergens())->toBe([]);
});

// -----------------------------------------------------------------------------
// Queue configuration that is invisible until it bites
// -----------------------------------------------------------------------------

it('keeps the timeout ladder in the required order', function () {
    // http timeout < job timeout < queue retry_after.
    //
    // If retry_after dropped below the job timeout, Redis would redeliver a
    // job that is still running: two workers, one document, two bills. The
    // framework default of 90 does exactly that against our 120s job timeout.
    expect((int) config('extraction.timeout'))
        ->toBeLessThan((int) config('extraction.job_timeout'))
        ->and((int) config('extraction.job_timeout'))
        ->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('uses jittered backoff so retries do not synchronise', function () {
    $job = app(ExtractLabelData::class);

    // Fixed backoff means an outage that fails 200 jobs has all 200 retry at
    // the same instant, guaranteeing the next failure too.
    $runs = collect(range(1, 8))->map(fn () => $job->getJobBackoff());

    expect($runs->first())->toHaveCount(3)
        ->and($runs->unique()->count())->toBeGreaterThan(1)
        ->and($runs->first()[0])->toBeLessThan($runs->first()[1]);
});

it('bounds total time with a deadline as well as a retry count', function () {
    expect(app(ExtractLabelData::class)->getJobRetryUntil())
        ->toBeGreaterThan(now())
        ->toBeLessThan(now()->addMinutes(20));
});
