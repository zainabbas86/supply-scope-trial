<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;
use App\Services\Extraction\ExtractionValidator;

beforeEach(function () {
    $this->validator = app(ExtractionValidator::class);
    $this->document = Document::factory()
        ->ownedBy(User::factory()->create())
        ->create(['page_count' => 3]);

    // The genuine model output, so "valid" means valid against reality rather
    // than against a payload written to satisfy the rules.
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/openai/coldwater-bay-response.json')),
        true,
    );

    foreach ($fixture['output'] as $item) {
        foreach ($item['content'] ?? [] as $chunk) {
            if (($chunk['type'] ?? '') === 'output_text') {
                $this->payload = json_decode($chunk['text'], true);
            }
        }
    }
});

/** @param  callable(array): array  $mutate */
function errorsFor(callable $mutate): array
{
    return test()->validator->validate($mutate(test()->payload), test()->document);
}

it('accepts the real captured model output', function () {
    expect($this->validator->validate($this->payload, $this->document))->toBe([]);
});

// -----------------------------------------------------------------------------
// Type-valid, semantically wrong — what strict:true cannot catch
// -----------------------------------------------------------------------------

it('rejects a negative net weight', function () {
    // Perfectly valid JSON, physically impossible.
    expect(errorsFor(function (array $p): array {
        $p['net_weight']['value'] = -5;

        return $p;
    }))->not->toBeEmpty();
});

it('rejects a citation beyond the document length', function () {
    // The model cited page 99 of a 3-page document — a hallucinated citation
    // that no schema can express a bound for.
    expect(errorsFor(function (array $p): array {
        $p['allergens']['source_page'] = 99;

        return $p;
    })[0])->toContain('source_page');
});

it('allows any citation when the page count is unknown', function () {
    // Images have no page count, so a page bound would have to be invented.
    $this->document->update(['page_count' => null]);

    expect(errorsFor(function (array $p): array {
        $p['allergens']['source_page'] = 99;

        return $p;
    }))->toBe([]);
});

it('rejects an absurdly long product name', function () {
    expect(errorsFor(function (array $p): array {
        $p['product_name']['value'] = str_repeat('a', 5000);

        return $p;
    }))->not->toBeEmpty();
});

it('rejects an enum value outside the schema', function () {
    expect(errorsFor(function (array $p): array {
        $p['net_weight']['unit'] = 'furlongs';

        return $p;
    }))->not->toBeEmpty();
});

// -----------------------------------------------------------------------------
// Cross-field rules — the safety-critical ones
// -----------------------------------------------------------------------------

it('refuses a declaration the manufacturer never made', function () {
    // THE rule this whole schema exists for. "not_completed" means the
    // statement was left blank, so anything in `declared` is the model
    // inventing an allergen declaration — on a food-safety document.
    expect(errorsFor(function (array $p): array {
        $p['allergens']['declared'] = ['Peanuts'];

        return $p;
    })[0])
        ->toContain('must be empty when statement_status is not_completed')
        ->toContain('derived_from_ingredients');
});

it('refuses a completed allergen statement on a non-food product', function () {
    expect(errorsFor(function (array $p): array {
        $p['product_type'] = 'non_food';
        $p['allergens']['statement_status'] = 'declared';

        return $p;
    })[0])->toContain('not_applicable');
});

it('refuses a weight with no unit', function () {
    // A number without a unit is not a weight, it is a number.
    expect(errorsFor(function (array $p): array {
        $p['net_weight']['unit'] = null;

        return $p;
    })[0])->toContain('net_weight.unit is required');
});

// -----------------------------------------------------------------------------
// The errors are a prompt, not a form message
// -----------------------------------------------------------------------------

it('reports machine field names so the repair prompt is actionable', function () {
    // Laravel humanises attribute names by default — "net weight.value". These
    // messages go straight back to the model, which has to emit the real path.
    $errors = errorsFor(function (array $p): array {
        $p['net_weight']['value'] = -5;

        return $p;
    });

    expect($errors[0])->toContain('net_weight.value')
        ->and($errors[0])->not->toContain('net weight.value');
});
