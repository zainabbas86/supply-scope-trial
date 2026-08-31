<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\ProductType;

/**
 * A validated extraction, plus what it cost to produce.
 *
 * Deliberately a typed object rather than the provider's raw array. Everything
 * downstream — persistence, the UI, the tests — depends on this shape, so the
 * moment a provider changes its response format the break happens here, at one
 * mapping site, instead of in twenty places that were reading array keys.
 *
 * `raw` is carried alongside so the unmodified response can still be stored: a
 * mapping bug can then be re-run against real data without paying for another
 * extraction.
 */
final readonly class ExtractionResult
{
    /**
     * @param  array<string, mixed>|null  $ingredients
     * @param  array<string, mixed>|null  $allergens
     * @param  array<string, mixed>|null  $netWeight
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $productName,
        public ?int $productNamePage,
        public ?string $brand,
        public ?int $brandPage,
        public ProductType $productType,
        public ?array $ingredients,
        public ?array $allergens,
        public ?array $netWeight,
        public array $warnings,
        public array $raw,
        public int $schemaVersion,
        public string $model,
        public string $promptVersion,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $latencyMs = null,
    ) {}

    /** Columns for the `extractions` table. @return array<string, mixed> */
    public function toExtractionAttributes(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'product_name' => $this->productName,
            'product_name_page' => $this->productNamePage,
            'brand' => $this->brand,
            'brand_page' => $this->brandPage,
            'product_type' => $this->productType,
            'ingredients' => $this->ingredients,
            'allergens' => $this->allergens,
            'net_weight' => $this->netWeight,
            'warnings' => $this->warnings,
            'raw_response' => $this->raw,
        ];
    }
}
