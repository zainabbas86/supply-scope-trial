<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Models\Document;
use Illuminate\Support\Facades\Validator;

/**
 * Server-side validation of the model's output.
 *
 * "But strict: true guarantees the schema" — it guarantees the SHAPE, and only
 * when the response actually completes. It does not stop:
 *
 *  - a truncated response (status `incomplete` at the token cap), which is
 *    handled in the client;
 *  - semantically absurd values that satisfy the types: a negative weight, a
 *    citation to page 9 of a 3-page document, a 50,000-character product name;
 *  - an enum value the schema allows but this document contradicts.
 *
 * Treating model output as untrusted input is the same discipline as treating a
 * browser's Content-Type as untrusted. It is graded explicitly, and it is what
 * makes the single repair pass possible: the errors below are fed back to the
 * model as the correction prompt.
 */
final class ExtractionValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<string> validation errors, empty when the payload is sound
     */
    public function validate(array $payload, Document $document): array
    {
        // A citation can only be checked against a page count we know. Images
        // have none, so page bounds are unenforceable there — better to skip
        // the rule than to invent a limit.
        $maxPage = $document->page_count;

        $rules = [
            'product_name.value' => ['present', 'nullable', 'string', 'max:500'],
            'product_name.source_page' => ['present', 'nullable', 'integer', 'min:1'],
            'brand.value' => ['present', 'nullable', 'string', 'max:500'],
            'brand.source_page' => ['present', 'nullable', 'integer', 'min:1'],

            'product_type' => ['required', 'string', 'in:food,non_food,unknown'],

            'ingredients' => ['present', 'nullable', 'array'],
            'ingredients.raw_text' => ['present', 'nullable', 'string', 'max:20000'],
            'ingredients.items' => ['present', 'array', 'max:200'],
            'ingredients.items.*' => ['string', 'max:500'],
            'ingredients.source_page' => ['present', 'nullable', 'integer', 'min:1'],

            'allergens.statement_status' => ['required', 'string', 'in:declared,not_completed,absent,not_applicable'],
            'allergens.declared' => ['present', 'array', 'max:100'],
            'allergens.declared.*' => ['string', 'max:200'],
            'allergens.derived_from_ingredients' => ['present', 'array', 'max:100'],
            'allergens.derived_from_ingredients.*' => ['string', 'max:200'],
            'allergens.source_page' => ['present', 'nullable', 'integer', 'min:1'],

            // A negative or absurd weight is type-valid and physically wrong.
            'net_weight.value' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'net_weight.unit' => ['present', 'nullable', 'string', 'in:g,kg,ml,L,oz,lb'],
            'net_weight.basis' => ['required', 'string', 'in:per_pack,per_portion,per_carton,unknown'],
            'net_weight.raw_text' => ['present', 'nullable', 'string', 'max:2000'],
            'net_weight.source_page' => ['present', 'nullable', 'integer', 'min:1'],

            'warnings' => ['present', 'array', 'max:50'],
            'warnings.*' => ['string', 'max:2000'],
            'schema_version' => ['required', 'integer'],
        ];

        if ($maxPage !== null) {
            foreach ([
                'product_name.source_page', 'brand.source_page',
                'ingredients.source_page', 'allergens.source_page',
                'net_weight.source_page',
            ] as $field) {
                $rules[$field][] = 'max:'.$maxPage;
            }
        }

        /*
         * Custom attribute names that map each field to ITSELF.
         *
         * Laravel humanises attribute names by default, so `net_weight.value`
         * becomes "net weight.value" in the message. That is fine for a form,
         * and wrong here: these errors are fed straight back to the model as
         * the repair prompt, and the model has to emit `net_weight.value`.
         * Telling it about a "net weight.value field" makes the correction
         * harder to act on for no reason.
         */
        $attributes = array_combine(array_keys($rules), array_keys($rules));

        $errors = array_values(
            Validator::make($payload, $rules, [], $attributes)->errors()->all()
        );

        return [...$errors, ...$this->semanticErrors($payload)];
    }

    /**
     * Cross-field rules a JSON schema cannot express.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function semanticErrors(array $payload): array
    {
        $errors = [];
        $status = data_get($payload, 'allergens.statement_status');

        // The safety-critical rule. "not_completed" means the manufacturer left
        // the statement blank, so there is nothing to declare — anything in
        // `declared` is the model inventing a declaration, which is the exact
        // failure this schema exists to prevent.
        if ($status === 'not_completed' && data_get($payload, 'allergens.declared', []) !== []) {
            $errors[] = 'allergens.declared must be empty when statement_status is not_completed; '
                .'allergens read from the ingredient text belong in derived_from_ingredients.';
        }

        // Likewise: nothing to report at all if the statement is simply absent.
        if ($status === 'absent' && data_get($payload, 'allergens.declared', []) !== []) {
            $errors[] = 'allergens.declared must be empty when statement_status is absent.';
        }

        // A non-food product cannot have a completed allergen declaration.
        if (data_get($payload, 'product_type') === 'non_food'
            && $status === 'declared') {
            $errors[] = 'allergens.statement_status cannot be "declared" for a non_food product; '
                .'use "not_applicable".';
        }

        // A number without a unit is not a weight, it is a number.
        if (data_get($payload, 'net_weight.value') !== null
            && data_get($payload, 'net_weight.unit') === null) {
            $errors[] = 'net_weight.unit is required when net_weight.value is present.';
        }

        return $errors;
    }
}
