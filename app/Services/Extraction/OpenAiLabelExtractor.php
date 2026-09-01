<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\ProductType;
use App\Exceptions\Extraction\RetryableExtractionException;
use App\Exceptions\Extraction\TerminalExtractionException;
use App\Models\Document;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Extracts label data using the OpenAI Responses API.
 *
 * Laravel's HTTP client directly rather than an SDK. Three reasons that hold up
 * under questioning: explicit control of both timeouts, error classification
 * precise enough to drive the retry decision, and `Http::fake()` for
 * transport-level tests without a wrapper in the way. A 0.x SDK would also put
 * version risk on the critical path for no benefit.
 *
 * Request shape verified live against gpt-5.5 on 2026-09-01: inline base64
 * `input_file`, `text.format` with `strict: true`, all pages read.
 */
class OpenAiLabelExtractor implements LabelExtractor
{
    public function __construct(
        private readonly ExtractionValidator $validator,
    ) {}

    public function extract(Document $document): ExtractionResult
    {
        $prompt = $this->prompt();
        $startedAt = hrtime(true);

        [$payload, $response] = $this->call($document, $prompt);

        $errors = $this->validator->validate($payload, $document);

        if ($errors !== []) {
            Log::warning('Extraction failed validation; attempting one repair.', [
                'document_id' => $document->id,
                'errors' => $errors,
            ]);

            /*
             * ONE repair round-trip, never a loop.
             *
             * The corrections are appended to the original prompt so the model
             * sees precisely what was wrong. If it still fails, that is
             * terminal: a model that has not converged after being told the
             * exact errors will not converge on the fifth attempt either, and
             * an unbounded loop against a metered API is how a bug becomes an
             * invoice.
             */
            [$payload, $response] = $this->call(
                $document,
                $prompt."\n\nYour previous answer was rejected for these reasons:\n- "
                    .implode("\n- ", $errors)
                    ."\n\nReturn a corrected answer. Do not invent values to satisfy these rules; "
                    .'use null and a warning where the document does not say.'
            );

            $errors = $this->validator->validate($payload, $document);

            if ($errors !== []) {
                throw TerminalExtractionException::invalidOutput(
                    'Validation failed after repair: '.implode('; ', $errors),
                    $response->json(),
                );
            }
        }

        return $this->toResult($payload, $response, $this->elapsedMs($startedAt));
    }

    /**
     * @return array{0: array<string, mixed>, 1: Response}
     */
    private function call(Document $document, string $prompt): array
    {
        $body = [
            'model' => (string) config('extraction.model'),
            'input' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_file',
                        'filename' => $document->original_filename,
                        // Inline base64 rather than an upload to the Files API:
                        // one request instead of two, nothing to clean up
                        // afterwards, and no orphaned files if the job dies.
                        'file_data' => $this->dataUri($document),
                    ],
                    ['type' => 'input_text', 'text' => $prompt],
                ],
            ]],
            'text' => ExtractionSchema::responseFormat(),
        ];

        try {
            $response = Http::withToken((string) config('extraction.api_key'))
                // Both timeouts set explicitly. Without connectTimeout, a
                // black-holed host consumes the whole 90s budget before the
                // first byte; the job then times out with nothing to show.
                ->connectTimeout((int) config('extraction.connect_timeout'))
                ->timeout((int) config('extraction.timeout'))
                ->acceptJson()
                ->post(rtrim((string) config('extraction.base_url'), '/').'/responses', $body);
        } catch (ConnectionException $e) {
            // Covers DNS failure, refused connections and read timeouts —
            // all transient, all worth another attempt.
            throw str_contains(strtolower($e->getMessage()), 'timed out')
                ? RetryableExtractionException::timedOut((int) config('extraction.timeout'))
                : RetryableExtractionException::connectionFailed($e->getMessage());
        }

        $this->assertHttpOk($response);

        return [$this->parse($response, $document), $response];
    }

    /**
     * Map HTTP reality onto the two exception types the job understands.
     *
     * The dividing question is never "was this an error?" but "would the same
     * request plausibly succeed shortly?".
     */
    private function assertHttpOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $raw = $response->json();
        $message = (string) data_get($raw, 'error.message', $response->body());

        throw match (true) {
            $status === 429 => RetryableExtractionException::rateLimited(
                (int) $response->header('retry-after') ?: null,
                $raw,
            ),
            $status >= 500 => RetryableExtractionException::serverError($status, $raw),
            in_array($status, [401, 403], true) => TerminalExtractionException::unauthorized($status),
            // 400 is the classic wasted-retry: it will be 400 all three times.
            default => TerminalExtractionException::badRequest($message, $raw),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(Response $response, Document $document): array
    {
        $body = $response->json();

        // `incomplete` means the model ran out of output tokens mid-answer. The
        // JSON is truncated, so parsing it produces either an error or — worse
        // — a plausible-looking partial record. Treated as retryable because a
        // fresh attempt genuinely can succeed.
        if (data_get($body, 'status') === 'incomplete') {
            throw RetryableExtractionException::serverError(
                200,
                ['incomplete_details' => data_get($body, 'incomplete_details')],
            );
        }

        foreach ((array) data_get($body, 'output', []) as $item) {
            foreach ((array) data_get($item, 'content', []) as $chunk) {
                // A refusal arrives on a 200. Without recognising it explicitly
                // it looks like malformed output and gets pointlessly retried.
                if (data_get($chunk, 'type') === 'refusal') {
                    throw TerminalExtractionException::refused(
                        (string) data_get($chunk, 'refusal'),
                        $body,
                    );
                }

                if (data_get($chunk, 'type') === 'output_text') {
                    try {
                        return (array) json_decode(
                            (string) data_get($chunk, 'text'),
                            true,
                            512,
                            JSON_THROW_ON_ERROR,
                        );
                    } catch (JsonException $e) {
                        throw TerminalExtractionException::invalidOutput(
                            'Model returned unparseable JSON: '.$e->getMessage(),
                            $body,
                        );
                    }
                }
            }
        }

        throw TerminalExtractionException::invalidOutput(
            'No output_text in the provider response.',
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toResult(array $payload, Response $response, int $latencyMs): ExtractionResult
    {
        $usage = (array) $response->json('usage', []);

        return new ExtractionResult(
            productName: data_get($payload, 'product_name.value'),
            productNamePage: data_get($payload, 'product_name.source_page'),
            brand: data_get($payload, 'brand.value'),
            brandPage: data_get($payload, 'brand.source_page'),
            productType: ProductType::tryFrom((string) data_get($payload, 'product_type')) ?? ProductType::Unknown,
            ingredients: data_get($payload, 'ingredients'),
            allergens: data_get($payload, 'allergens'),
            netWeight: data_get($payload, 'net_weight'),
            warnings: (array) data_get($payload, 'warnings', []),
            raw: (array) $response->json(),
            schemaVersion: (int) data_get($payload, 'schema_version', ExtractionSchema::VERSION),
            model: (string) ($response->json('model') ?? config('extraction.model')),
            promptVersion: (string) config('extraction.prompt_version'),
            inputTokens: data_get($usage, 'input_tokens'),
            outputTokens: data_get($usage, 'output_tokens'),
            latencyMs: $latencyMs,
        );
    }

    private function dataUri(Document $document): string
    {
        // Read via the disk recorded ON THE DOCUMENT, so files stored before a
        // move to S3 stay readable afterwards.
        $contents = Storage::disk($document->disk)->get($document->storage_path);

        if ($contents === null) {
            throw TerminalExtractionException::fileMissing($document->storage_path);
        }

        return 'data:'.$document->mime_type.';base64,'.base64_encode($contents);
    }

    private function prompt(): string
    {
        $version = (string) config('extraction.prompt_version');
        $path = resource_path("prompts/extract_{$version}.txt");

        // Versioned on disk, not inlined: `prompt_version` is recorded on every
        // attempt, so a bad batch of results can be traced to the prompt that
        // produced it. An inline string makes that impossible after an edit.
        return trim((string) file_get_contents($path));
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
