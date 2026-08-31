<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured result of a successful extraction. One row per document.
 *
 * @property ProductType $product_type
 * @property array|null $ingredients
 * @property array|null $allergens
 * @property array|null $net_weight
 */
class Extraction extends Model
{
    /** @use HasFactory<ExtractionFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'schema_version' => 'integer',
            'product_name_page' => 'integer',
            'brand_page' => 'integer',
            'ingredients' => 'array',
            'allergens' => 'array',
            'net_weight' => 'array',
            'warnings' => 'array',
            'raw_response' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    // -----------------------------------------------------------------------
    // Allergen helpers
    //
    // These exist because the honest answer is not "the allergen list". One
    // sample's allergen statement reads "VITAL NOT COMPLETED" while Fish, Wheat
    // and Milk appear only as bold words inside the ingredient declaration.
    // Reporting an empty list there would be a dangerous lie; reporting them as
    // "declared" would be inventing a declaration that does not exist.
    // -----------------------------------------------------------------------

    /** Was an explicit allergen statement actually completed on the document? */
    public function allergenStatementStatus(): ?string
    {
        return $this->allergens['statement_status'] ?? null;
    }

    /** True when the document has an allergen section that was left incomplete. */
    public function hasIncompleteAllergenStatement(): bool
    {
        return $this->allergenStatementStatus() === 'not_completed';
    }

    /** Allergens from an explicit statement. @return list<string> */
    public function declaredAllergens(): array
    {
        return $this->allergens['declared'] ?? [];
    }

    /** Allergens inferred from the ingredient text — NOT a declaration. @return list<string> */
    public function derivedAllergens(): array
    {
        return $this->allergens['derived_from_ingredients'] ?? [];
    }

    /**
     * Net weight is ambiguous by nature: the same page may state a portion
     * weight, a pack weight and a carton weight. The basis is what makes the
     * number mean anything, so it is never dropped.
     */
    public function netWeightBasis(): ?string
    {
        return $this->net_weight['basis'] ?? null;
    }
}
