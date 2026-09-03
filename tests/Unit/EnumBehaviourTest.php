<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\ExtractionOutcome;
use App\Enums\ProductType;

/*
|--------------------------------------------------------------------------
| Genuine unit tests — no framework, no database, no container
|--------------------------------------------------------------------------
|
| These enums are not value bags. They carry the decisions the queue makes:
| whether a redelivered job should exit, and whether a failure is worth
| retrying. That logic is worth testing in isolation, where a failure points at
| the enum rather than at whatever wiring happened to exercise it.
|
| tests/Pest.php deliberately does NOT bind Unit tests to the Laravel TestCase,
| so anything here that reaches for the framework fails immediately — which is
| what keeps "unit" honest.
|
*/

describe('DocumentStatus', function () {
    it('treats only completed and failed as terminal', function () {
        // The job checks this first: a redelivered message for a finished
        // document must not re-extract and re-bill.
        expect(DocumentStatus::Completed->isTerminal())->toBeTrue()
            ->and(DocumentStatus::Failed->isTerminal())->toBeTrue()
            ->and(DocumentStatus::Queued->isTerminal())->toBeFalse()
            ->and(DocumentStatus::Processing->isTerminal())->toBeFalse();
    });

    it('treats in-flight as the exact inverse of terminal', function () {
        // The UI polls on this. If the two ever disagreed, a finished document
        // would either spin forever or stop updating before it finished.
        foreach (DocumentStatus::cases() as $status) {
            expect($status->isInFlight())->toBe(! $status->isTerminal());
        }
    });

    it('gives every case a human label', function () {
        foreach (DocumentStatus::cases() as $status) {
            expect($status->label())->not->toBeEmpty()
                // A status badge showing "not_completed" is a leaked identifier.
                ->and($status->label())->not->toContain('_');
        }
    });

    it('exposes its values for validation rules', function () {
        expect(DocumentStatus::values())
            ->toBe(['queued', 'processing', 'completed', 'failed']);
    });
});

describe('ExtractionOutcome', function () {
    it('marks only a rate limit or provider blip as retryable', function () {
        // This is the retry decision. Marking a terminal error retryable burns
        // the budget on something that cannot succeed; marking a transient one
        // terminal throws away work that would have succeeded seconds later.
        expect(ExtractionOutcome::RetryableError->isRetryable())->toBeTrue()
            ->and(ExtractionOutcome::TerminalError->isRetryable())->toBeFalse()
            ->and(ExtractionOutcome::InvalidOutput->isRetryable())->toBeFalse()
            ->and(ExtractionOutcome::Success->isRetryable())->toBeFalse();
    });

    it('counts everything except success as a failure', function () {
        expect(ExtractionOutcome::Success->isFailure())->toBeFalse();

        foreach (ExtractionOutcome::cases() as $outcome) {
            if ($outcome !== ExtractionOutcome::Success) {
                expect($outcome->isFailure())->toBeTrue();
            }
        }
    });

    it('never reports an outcome as both retryable and successful', function () {
        foreach (ExtractionOutcome::cases() as $outcome) {
            expect($outcome->isRetryable() && ! $outcome->isFailure())->toBeFalse();
        }
    });
});

describe('ProductType', function () {
    it('expects allergens only for food', function () {
        // One sample is a cleaning chemical. Reporting an empty allergen list
        // there reads as "no allergens", which is a dangerous thing to say by
        // accident about a product allergen rules do not even apply to.
        expect(ProductType::Food->expectsAllergens())->toBeTrue()
            ->and(ProductType::NonFood->expectsAllergens())->toBeFalse()
            // Unknown is not food, so it makes no allergen claim either.
            ->and(ProductType::Unknown->expectsAllergens())->toBeFalse();
    });

    it('labels non-food readably', function () {
        expect(ProductType::NonFood->label())->toBe('Non-food');
    });
});
