<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Document;
use App\Models\Extraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    protected $model = Extraction::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory()->completed(),
            'schema_version' => 1,

            // Non-ASCII by default, on purpose. The real sample returns
            // "Coldwater Bay – Fish – Fillets – Battered" with en-dashes, and
            // a broken encoding anywhere in the chain corrupts it silently.
            // Making the default value non-ASCII means any test that round-trips
            // an extraction is also an encoding test.
            'product_name' => 'Coldwater Bay – Fish – Fillets – Battered',
            'product_name_page' => 1,
            'brand' => 'Coldwater Bay',
            'brand_page' => 1,
            'product_type' => ProductType::Food,

            'ingredients' => [
                'raw_text' => 'Alaska Pollock (Fish) 60%, Wheat Flour, Water, Milk Powder, Salt.',
                'items' => ['Alaska Pollock', 'Wheat Flour', 'Water', 'Milk Powder', 'Salt'],
                'source_page' => 2,
            ],

            'allergens' => [
                'statement_status' => 'declared',
                'declared' => ['Fish', 'Wheat', 'Milk'],
                'derived_from_ingredients' => [],
                'source_page' => 2,
            ],

            'net_weight' => [
                'value' => 800,
                'unit' => 'g',
                'basis' => 'per_pack',
                'raw_text' => 'NET Weight/Pack 800 g',
                'source_page' => 1,
            ],

            'warnings' => [],
            'raw_response' => ['stub' => true],
        ];
    }

    /**
     * The trap case from the sample set: the allergen statement is present but
     * was never completed, and the allergens exist only as bold words inside
     * the ingredient declaration.
     *
     * Any UI or reporting change should be tested against this, because it is
     * the case where a naive implementation reports "no allergens" on a product
     * that contains three.
     */
    public function withIncompleteAllergenStatement(): static
    {
        return $this->state(fn () => [
            'allergens' => [
                'statement_status' => 'not_completed',
                'declared' => [],
                'derived_from_ingredients' => ['Fish', 'Wheat', 'Milk'],
                'source_page' => 2,
            ],
            'warnings' => [
                'Section 8 reads "ALLERGEN STATEMENT — VITAL NOT COMPLETED"; allergens were derived from the ingredient declaration.',
            ],
        ]);
    }

    /** The cleaning-chemical sample: not food, so allergens do not apply. */
    public function nonFood(): static
    {
        return $this->state(fn () => [
            'product_name' => 'Halcyon Works Surface Spray',
            'brand' => 'Halcyon Works',
            'product_type' => ProductType::NonFood,
            'allergens' => [
                'statement_status' => 'not_applicable',
                'declared' => [],
                'derived_from_ingredients' => [],
                'source_page' => null,
            ],
        ]);
    }

    /** Nothing could be read — every field null, with a reason. */
    public function unreadable(): static
    {
        return $this->state(fn () => [
            'product_name' => null,
            'product_name_page' => null,
            'brand' => null,
            'brand_page' => null,
            'product_type' => ProductType::Unknown,
            'ingredients' => null,
            'allergens' => null,
            'net_weight' => null,
            'warnings' => ['No legible product information was found on the document.'],
        ]);
    }
}
