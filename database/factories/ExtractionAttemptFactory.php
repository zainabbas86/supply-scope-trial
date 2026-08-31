<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtractionOutcome;
use App\Models\Document;
use App\Models\ExtractionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtractionAttempt>
 */
class ExtractionAttemptFactory extends Factory
{
    protected $model = ExtractionAttempt::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'attempt_no' => 1,
            'model' => config('extraction.model', 'gpt-5.5'),
            'prompt_version' => 'v1',
            'outcome' => ExtractionOutcome::Success,
            'error_class' => null,
            'error_message' => null,
            'http_status' => 200,

            // Anchored to a real measurement: the coldwater-bay sample took
            // ~18s for 3 pages at 3,191 input / 837 output tokens.
            'latency_ms' => fake()->numberBetween(12_000, 22_000),
            'input_tokens' => fake()->numberBetween(2_800, 3_600),
            'output_tokens' => fake()->numberBetween(600, 1_000),
            'raw_response' => ['stub' => true],
        ];
    }

    /** Rate limited — retryable, so the queue should try again. */
    public function rateLimited(): static
    {
        return $this->state(fn () => [
            'outcome' => ExtractionOutcome::RetryableError,
            'error_class' => 'Illuminate\Http\Client\RequestException',
            'error_message' => 'Rate limit reached for gpt-5.5.',
            'http_status' => 429,
            'output_tokens' => null,
        ]);
    }

    /** A 400 — permanent. Retrying wastes the budget and delays the failure. */
    public function terminalError(): static
    {
        return $this->state(fn () => [
            'outcome' => ExtractionOutcome::TerminalError,
            'error_class' => 'Illuminate\Http\Client\RequestException',
            'error_message' => 'Unsupported file format for vision input.',
            'http_status' => 400,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);
    }

    /** The model answered, but the payload failed server-side validation. */
    public function invalidOutput(): static
    {
        return $this->state(fn () => [
            'outcome' => ExtractionOutcome::InvalidOutput,
            'error_class' => 'App\Exceptions\InvalidExtractionPayload',
            'error_message' => 'net_weight.value must be greater than or equal to 0.',
            'http_status' => 200,
        ]);
    }

    public function timedOut(): static
    {
        return $this->state(fn () => [
            'outcome' => ExtractionOutcome::RetryableError,
            'error_class' => 'Illuminate\Http\Client\ConnectionException',
            'error_message' => 'cURL error 28: Operation timed out after 90000 milliseconds.',
            'http_status' => null,
            'latency_ms' => 90_000,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);
    }

    public function attempt(int $number): static
    {
        return $this->state(fn () => ['attempt_no' => $number]);
    }
}
