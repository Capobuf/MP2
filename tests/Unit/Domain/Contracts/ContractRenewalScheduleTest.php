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
