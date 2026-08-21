<?php

use App\Domain\Contracts\ContractRenewalSchedule;

it('enumerates every elapsed renewal from the approved anchor and exposes the first future expiry', function () {
    $schedule = ContractRenewalSchedule::fromAnchor('2023-01-31', 12, '2026-08-20');

    expect($schedule->elapsed)->toBe(['2023-01-31', '2024-01-31', '2025-01-31', '2026-01-31'])
        ->and($schedule->nextExpiry)->toBe('2027-01-31');
});

it('uses the original renewal anchor for month-end recurrence', function () {
    $schedule = ContractRenewalSchedule::fromAnchor('2024-01-31', 1, '2024-04-15');

    expect($schedule->elapsed)->toBe(['2024-01-31', '2024-02-29', '2024-03-31'])
        ->and($schedule->nextExpiry)->toBe('2024-04-30');
});

it('selects the complete historical configuration effective at each expiry', function () {
    $configurations = [
        ['id' => 10, 'effective_from' => '2024-01-01', 'automatic_renewal' => true, 'expiry_anchor_date' => '2024-12-31', 'renewal_duration_months' => 12],
        ['id' => 20, 'effective_from' => '2026-01-01', 'automatic_renewal' => false, 'expiry_anchor_date' => '2026-12-31', 'renewal_duration_months' => null],
    ];

    expect(ContractRenewalSchedule::configurationAtDate($configurations, '2025-12-31')['id'])->toBe(10)
        ->and(ContractRenewalSchedule::configurationAtDate($configurations, '2026-12-31')['id'])->toBe(20);
});

it('projects anchored renewals and detects renewal without later economic coverage', function () {
    expect(ContractRenewalSchedule::nextAnchoredExpiry('2024-01-31', 12, '2025-01-31'))->toBe('2026-01-31')
        ->and(ContractRenewalSchedule::hasRenewalWithoutCondition([
            ['valid_from' => '2024-01-01', 'valid_to' => '2025-01-31', 'annulled_at' => null],
        ], '2025-01-31'))->toBeTrue()
        ->and(ContractRenewalSchedule::hasRenewalWithoutCondition([
            ['valid_from' => '2024-01-01', 'valid_to' => null, 'annulled_at' => null],
        ], '2025-01-31'))->toBeFalse();
});
