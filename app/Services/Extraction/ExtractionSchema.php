<?php

declare(strict_types=1);

namespace App\Services\Extraction;

/**
 * The JSON schema sent to OpenAI as a Structured Output contract.
 *
 * Kept in ONE place because it is used twice — in the request, and as the shape
 * the validator expects back. Written out twice, the two drift, and the failure
 * looks like the model misbehaving rather than the schema disagreeing with
 * itself.
 *
 * Verified live against gpt-5.5 on 2026-09-01.
 *
 * Two strict-mode rules that are easy to get wrong and reject the whole request:
 *
 *  1. EVERY property must appear in `required`, and every object needs
 *     `additionalProperties: false`. Optionality is expressed by allowing null,
 *     not by omitting the key.
 *  2. A nullable enum needs `null` in the `enum` array AND "null" in the type
 *     array. Either alone is rejected.
 */
final class ExtractionSchema
{
    public const VERSION = 1;

    public const NAME = 'label_extraction';

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_name' => self::cited('string'),
                'brand' => self::cited('string'),

                'product_type' => [
                    'type' => 'string',
                    'enum' => ['food', 'non_food', 'unknown'],
                ],

                'ingredients' => [
                    'type' => 'object',
                    'properties' => [
                        'raw_text' => self::nullable('string'),
                        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'source_page' => self::nullable('integer'),
                    ],
                    'required' => ['raw_text', 'items', 'source_page'],
                    'additionalProperties' => false,
                ],

                /*
                 * The heart of the schema.
                 *
                 * Splitting `declared` from `derived_from_ingredients` is what
                 * lets the app say "the allergen statement was left incomplete,
                 * but Fish, Wheat and Milk appear in the ingredients" — instead
                 * of choosing between reporting no allergens (dangerously
                 * wrong) and inventing a declaration that does not exist.
                 *
                 * Confirmed on the real sample: the model returned
                 * not_completed / [] / [Fish, Wheat, Milk] with a warning.
                 */
                'allergens' => [
                    'type' => 'object',
                    'properties' => [
                        'statement_status' => [
                            'type' => 'string',
                            'enum' => ['declared', 'not_completed', 'absent', 'not_applicable'],
                        ],
                        'declared' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'derived_from_ingredients' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'source_page' => self::nullable('integer'),
                    ],
                    'required' => ['statement_status', 'declared', 'derived_from_ingredients', 'source_page'],
                    'additionalProperties' => false,
                ],

                /*
                 * `basis` is not decoration. One sample page states 112 g per
                 * portion, 800 g per pack and 800 g x 4 per carton. A bare
                 * "800g" throws away which of the three it is.
                 */
                'net_weight' => [
                    'type' => 'object',
                    'properties' => [
                        'value' => self::nullable('number'),
                        'unit' => [
                            'type' => ['string', 'null'],
                            'enum' => ['g', 'kg', 'ml', 'L', 'oz', 'lb', null],
                        ],
                        'basis' => [
                            'type' => 'string',
                            'enum' => ['per_pack', 'per_portion', 'per_carton', 'unknown'],
                        ],
                        'raw_text' => self::nullable('string'),
                        'source_page' => self::nullable('integer'),
                    ],
                    'required' => ['value', 'unit', 'basis', 'raw_text', 'source_page'],
                    'additionalProperties' => false,
                ],

                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'schema_version' => ['type' => 'integer'],
            ],
            'required' => [
                'product_name', 'brand', 'product_type', 'ingredients',
                'allergens', 'net_weight', 'warnings', 'schema_version',
            ],
            'additionalProperties' => false,
        ];
    }

    /** The `text.format` block for the Responses API. @return array<string, mixed> */
    public static function responseFormat(): array
    {
        return [
            'format' => [
                // Responses API uses text.format. `response_format` is the Chat
                // Completions spelling and is silently ignored here — you get
                // unstructured prose back and wonder why parsing fails.
                'type' => 'json_schema',
                'name' => self::NAME,
                'strict' => true,
                'schema' => self::definition(),
            ],
        ];
    }

    /** A value plus the page it was read from. @return array<string, mixed> */
    private static function cited(string $type): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => self::nullable($type),
                'source_page' => self::nullable('integer'),
            ],
            'required' => ['value', 'source_page'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function nullable(string $type): array
    {
        return ['type' => [$type, 'null']];
    }
}
