<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of product the document describes.
 *
 * This exists because one of the sample specification sheets is a cleaning
 * chemical, not food. "Ingredients" means something different there and
 * "allergens" barely applies, so the app has to be able to say "this is not
 * food" rather than emit an empty allergen list that reads as "no allergens" —
 * which, on a food-safety product, is a dangerous thing to say by accident.
 *
 * `Unknown` is a first-class value, not a failure: an honest "I could not tell"
 * is better than a confident guess.
 */
enum ProductType: string
{
    case Food = 'food';
    case NonFood = 'non_food';
    case Unknown = 'unknown';

    /** Allergen declarations are only meaningful for food. */
    public function expectsAllergens(): bool
    {
        return $this === self::Food;
    }

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Food',
            self::NonFood => 'Non-food',
            self::Unknown => 'Unknown',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
