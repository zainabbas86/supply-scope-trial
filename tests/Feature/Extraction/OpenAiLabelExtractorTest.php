<?php

declare(strict_types=1);

use App\Exceptions\Extraction\RetryableExtractionException;
use App\Exceptions\Extraction\TerminalExtractionException;
use App\Models\Document;
use App\Models\User;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\ExtractionSchema;
use App\Services\Extraction\OpenAiLabelExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    // Nothing here reaches the network — but if a stub were ever missing,
    // this turns a silent real request into an immediate failure.
    Http::preventStrayRequests();

    $this->document = Document::factory()
        ->ownedBy(User::factory()->create())
        ->create(['disk' => 'local', 'page_count' => 3]);

    Storage::disk('local')->put($this->document->storage_path, 'pdf-bytes');

    $this->fixture = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/openai/coldwater-bay-response.json')),
        true,
    );
});

// Named runExtractor, not extract: runExtractor() is a PHP built-in.
function runExtractor(): ExtractionResult
{
    return app(OpenAiLabelExtractor::class)->extract(test()->document);
}

/** Replace the model's answer inside a real response envelope. */
function responseWith(array $fixture, array $payload): array
{
    foreach ($fixture['output'] as $i => $item) {
        foreach ($item['content'] ?? [] as $j => $chunk) {
            if (($chunk['type'] ?? '') === 'output_text') {
                $fixture['output'][$i]['content'][$j]['text'] = json_encode($payload);
            }
        }
    }

    return $fixture;
}

function payloadOf(array $fixture): array
{
    foreach ($fixture['output'] as $item) {
        foreach ($item['content'] ?? [] as $chunk) {
            if (($chunk['type'] ?? '') === 'output_text') {
                return json_decode($chunk['text'], true);
            }
        }
    }

    return [];
}

// -----------------------------------------------------------------------------
// The real captured response
// -----------------------------------------------------------------------------

it('parses a real captured provider response', function () {
    // A genuine gpt-5.5 reply, recorded live. A hand-written fixture only ever
    // proves the code agrees with my assumptions about the API.
    Http::fake(['*' => Http::response($this->fixture, 200)]);

    $result = runExtractor();

    expect($result->productName)->toBe('Coldwater Bay – Fish – Fillets – Battered')
        ->and($result->productType->value)->toBe('food')
        ->and($result->allergens['statement_status'])->toBe('not_completed')
        ->and($result->allergens['derived_from_ingredients'])->toBe(['Fish', 'Wheat', 'Milk'])
        ->and($result->allergens['declared'])->toBe([])
        ->and($result->netWeight['value'])->toBe(800)
        ->and($result->netWeight['basis'])->toBe('per_pack')
        ->and($result->inputTokens)->toBe(3417)
        ->and($result->model)->toContain('gpt-5.5');
});

it('reads the answer from the message item, not the first output item', function () {
    // The Responses API puts a `reasoning` item first, with an EMPTY content
    // array; the text lives in the `message` item after it. Reaching for
    // output[0].content[0] — the obvious thing to write — parses nothing.
    expect($this->fixture['output'][0]['type'])->toBe('reasoning')
        ->and($this->fixture['output'][0]['content'])->toBe([])
        ->and($this->fixture['output'][1]['type'])->toBe('message');

    Http::fake(['*' => Http::response($this->fixture, 200)]);

    expect(runExtractor()->productName)->not->toBeNull();
});

it('sends the request shape the responses api requires', function () {
    Http::fake(['*' => Http::response($this->fixture, 200)]);
    runExtractor();

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        expect($request->url())->toEndWith('/responses')
            ->and($request->hasHeader('Authorization'))->toBeTrue()
            // text.format, NOT response_format — the latter is the Chat
            // Completions spelling and is ignored here, so the model would
            // return prose and every parse would fail.
            ->and($body['text']['format']['type'])->toBe('json_schema')
            ->and($body['text']['format']['strict'])->toBeTrue()
            ->and($body['text']['format']['name'])->toBe(ExtractionSchema::NAME)
            ->and(array_column($body['input'][0]['content'], 'type'))
            ->toBe(['input_file', 'input_text'])
            ->and($body['input'][0]['content'][0]['file_data'])
            ->toStartWith('data:application/pdf;base64,');

        return true;
    });
});

// -----------------------------------------------------------------------------
// Error classification — the retry decision
// -----------------------------------------------------------------------------

it('classifies transport failures as retryable or terminal', function (int $status, string $class, string $code) {
    Http::fake(['*' => Http::response(['error' => ['message' => 'x']], $status)]);

    expect(fn () => runExtractor())->toThrow($class);

    try {
        runExtractor();
    } catch (Throwable $e) {
        expect($e->failureCode)->toBe($code);
    }
})->with([
    'rate limited' => [429, RetryableExtractionException::class, 'rate_limited'],
    'server error' => [500, RetryableExtractionException::class, 'provider_error'],
    'unavailable' => [503, RetryableExtractionException::class, 'provider_error'],
    'bad credentials' => [401, TerminalExtractionException::class, 'provider_auth_failed'],
    'bad request' => [400, TerminalExtractionException::class, 'invalid_request'],
]);

it('treats a truncated response as retryable', function () {
    // `incomplete` means the model ran out of output tokens mid-answer. A
    // fresh attempt genuinely can succeed, so this is not terminal — but the
    // partial JSON must never be parsed as if it were complete.
    Http::fake(['*' => Http::response([
        'status' => 'incomplete',
        'incomplete_details' => ['reason' => 'max_output_tokens'],
    ], 200)]);

    expect(fn () => runExtractor())->toThrow(RetryableExtractionException::class);
});

it('treats a refusal as terminal even though it arrives on a 200', function () {
    Http::fake(['*' => Http::response([
        'status' => 'completed',
        'output' => [['content' => [['type' => 'refusal', 'refusal' => 'I cannot assist.']]]],
    ], 200)]);

    try {
        runExtractor();
    } catch (TerminalExtractionException $e) {
        // Without recognising refusals explicitly they look like malformed
        // output and get retried three times for nothing.
        expect($e->failureCode)->toBe('model_refusal');
    }
});

it('rejects unparseable json without retrying', function () {
    Http::fake(['*' => Http::response([
        'status' => 'completed',
        'output' => [['content' => [['type' => 'output_text', 'text' => '{ not json']]]],
    ], 200)]);

    expect(fn () => runExtractor())->toThrow(TerminalExtractionException::class);
});

it('rejects a response with no answer in it', function () {
    Http::fake(['*' => Http::response(['status' => 'completed', 'output' => []], 200)]);

    expect(fn () => runExtractor())->toThrow(TerminalExtractionException::class);
});

// -----------------------------------------------------------------------------
// Untrusted output — the bounded repair pass
// -----------------------------------------------------------------------------

it('repairs one invalid response and accepts the correction', function () {
    // strict:true guarantees the SHAPE, not the meaning. A negative weight is
    // perfectly type-valid and physically impossible.
    $broken = payloadOf($this->fixture);
    $broken['net_weight']['value'] = -1;

    Http::fakeSequence()
        ->push(responseWith($this->fixture, $broken), 200)
        ->push($this->fixture, 200);

    expect(runExtractor()->netWeight['value'])->toBe(800);

    expect(Http::recorded())->toHaveCount(2);
});

it('gives up after one repair rather than looping', function () {
    $broken = payloadOf($this->fixture);
    $broken['net_weight']['value'] = -1;

    Http::fake(['*' => Http::response(responseWith($this->fixture, $broken), 200)]);

    expect(fn () => runExtractor())->toThrow(TerminalExtractionException::class);

    // Exactly two calls: the attempt and one correction. A model that has not
    // converged after being told the precise errors will not converge on the
    // fifth attempt, and an unbounded loop against a metered API is how a bug
    // becomes an invoice.
    expect(Http::recorded())->toHaveCount(2);
});

it('feeds the validation errors back into the repair prompt', function () {
    $broken = payloadOf($this->fixture);
    $broken['net_weight']['value'] = -1;

    Http::fakeSequence()
        ->push(responseWith($this->fixture, $broken), 200)
        ->push($this->fixture, 200);

    runExtractor();

    $second = Http::recorded()[1][0]->data();
    $prompt = $second['input'][0]['content'][1]['text'];

    // Telling the model only "that was wrong" wastes the round trip.
    expect($prompt)->toContain('previous answer was rejected')
        ->and($prompt)->toContain('net_weight.value')
        ->and($prompt)->toContain('Do not invent values');
});
