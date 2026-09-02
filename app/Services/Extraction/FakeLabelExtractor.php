<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\ProductType;
use App\Exceptions\Extraction\RetryableExtractionException;
use App\Exceptions\Extraction\TerminalExtractionException;
use App\Models\Document;
use Throwable;

/**
 * A scriptable extractor, bound in place of the real one during tests.
 *
 * This is what makes the §6 suite possible: "429 twice then succeed" is a
 * one-line script here, and utterly impractical against a live API. It is also
 * why the whole reliability layer could be verified before the OpenAI client
 * existed at all.
 *
 * Usage:
 *
 *     FakeLabelExtractor::script([
 *         RetryableExtractionException::rateLimited(),
 *         RetryableExtractionException::rateLimited(),
 *         'success',
 *     ]);
 *
 * Each call to extract() consumes the next entry. Once the script runs out, the
 * last entry repeats — so "always fails" is a single-element script.
 */
class FakeLabelExtractor implements LabelExtractor
{
    /** @var list<Throwable|string> */
    private array $script = ['success'];

    private int $calls = 0;

    /** @param list<Throwable|string> $script */
    public function setScript(array $script): void
    {
        $this->script = $script === [] ? ['success'] : $script;
        $this->calls = 0;
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    public function extract(Document $document): ExtractionResult
    {
        $step = $this->script[min($this->calls, count($this->script) - 1)];
        $this->calls++;

        if ($step instanceof Throwable) {
            throw $step;
        }

        return match ($step) {
            'refusal' => throw TerminalExtractionException::refused('Cannot assist with that.'),
            'invalid' => throw TerminalExtractionException::invalidOutput('net_weight.value must be >= 0.'),
            'non_food' => $this->nonFood(),
            default => $this->success(),
        };
    }

    /**
     * MIRRORS the real captured response for the coldwater-bay sample
     * (tests/Fixtures/openai/coldwater-bay-response.json) verbatim —
     * including the trap: the allergen statement was never completed, so the
     * allergens exist only as bold words inside the ingredient declaration.
     * A fake that returned tidy data would let a bug through that the real
     * documents would expose immediately.
     */
    private function success(): ExtractionResult
    {
        return new ExtractionResult(
            // Non-ASCII on purpose: en-dashes must survive the round trip.
            productName: 'Coldwater Bay – Fish – Fillets – Battered',
            productNamePage: 1,
            brand: 'Coldwater Bay',
            brandPage: 1,
            productType: ProductType::Food,
            ingredients: [
                'raw_text' => 'Fish (Hoki) (58%), Water, Wheat Flour, Canola Oil, Maize Starch, Salt, Raising Agents (450, 500), Milk Solids, Dextrose, Yeast, Spice Extracts, Natural Colour (160b).',
                'items' => [
                    'Fish (Hoki) (58%)',
                    'Water',
                    'Wheat Flour',
                    'Canola Oil',
                    'Maize Starch',
                    'Salt',
                    'Raising Agents (450, 500)',
                    'Milk Solids',
                    'Dextrose',
                    'Yeast',
                    'Spice Extracts',
                    'Natural Colour (160b)',
                ],
                'source_page' => 2,
            ],
            allergens: [
                'statement_status' => 'not_completed',
                'declared' => [],
                'derived_from_ingredients' => ['Fish', 'Wheat', 'Milk'],
                'source_page' => 2,
            ],
            netWeight: [
                'value' => 800,
                'unit' => 'g',
                'basis' => 'per_pack',
                'raw_text' => 'NET Weight / Pack: 800 g; Pack size: 800g x 4 bags / carton',
                'source_page' => 1,
            ],
            warnings: ['Allergen statement is marked "VITAL NOT COMPLETED" / "Not completed"; declared allergens are not available, so allergens listed are derived only from the ingredient declaration.'],
            // ^ verbatim from the real captured response, not invented.
            raw: ['fake' => true],
            schemaVersion: (int) config('extraction.schema_version', 1),
            model: 'fake',
            promptVersion: (string) config('extraction.prompt_version', 'v1'),
            inputTokens: 3417,
            outputTokens: 807,
            latencyMs: 15_834,
        );
    }

    /** The cleaning-chemical sample: not food, so allergens do not apply. */
    private function nonFood(): ExtractionResult
    {
        return new ExtractionResult(
            productName: 'Halcyon Works Surface Spray',
            productNamePage: 1,
            brand: 'Halcyon Works',
            brandPage: 1,
            productType: ProductType::NonFood,
            ingredients: [
                'raw_text' => 'Aqua, Alcohol Denat., Surfactants, Perfume.',
                'items' => ['Aqua', 'Alcohol Denat.', 'Surfactants', 'Perfume'],
                'source_page' => 2,
            ],
            allergens: [
                'statement_status' => 'not_applicable',
                'declared' => [],
                'derived_from_ingredients' => [],
                'source_page' => null,
            ],
            netWeight: [
                'value' => 750,
                'unit' => 'ml',
                'basis' => 'per_pack',
                'raw_text' => '750 ml',
                'source_page' => 1,
            ],
            warnings: ['Product is not a food; allergen labelling rules do not apply.'],
            raw: ['fake' => true],
            schemaVersion: (int) config('extraction.schema_version', 1),
            model: 'fake',
            promptVersion: (string) config('extraction.prompt_version', 'v1'),
            inputTokens: 2800,
            outputTokens: 620,
            latencyMs: 15_000,
        );
    }

    /**
     * Convenience for tests: bind a scripted fake into the container.
     *
     * @param  list<Throwable|string>  $script
     */
    public static function script(array $script): self
    {
        $fake = new self;
        $fake->setScript($script);
        app()->instance(LabelExtractor::class, $fake);

        return $fake;
    }
}
