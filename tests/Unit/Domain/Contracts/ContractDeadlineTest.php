<?php

use App\Domain\Contracts\ContractDeadline;
use App\Domain\Contracts\ContractState;

it('derives exact informative expiry notice and planned-cessation fields', function () {
    $deadline = ContractDeadline::derive(
        contractualStartDate: '2026-01-01',
        nextExpiryDate: '2026-12-31',
        automaticRenewal: true,
        renewalDurationMonths: 12,
        noticeDays: 60,
        today: '2026-08-21',
        state: ContractState::Active,
        plannedCessationDate: '2026-11-30',
        costCenterId: 12,
        renewalWithoutCondition: true,
    );

    expect($deadline->nextExpiryDate)->toBe('2026-12-31')
        ->and($deadline->noticeLimitDate)->toBe('2026-11-01')
        ->and($deadline->daysUntilExpiry)->toBe(132)
        ->and($deadline->daysUntilNoticeLimit)->toBe(72)
        ->and($deadline->plannedCessationDate)->toBe('2026-11-30')
        ->and($deadline->costCenterId)->toBe(12)
        ->and($deadline->renewalWithoutCondition)->toBeTrue();
});

it('keeps undefined expiry and notice calculations explicitly absent', function () {
    $deadline = ContractDeadline::derive(
        contractualStartDate: '2026-01-01',
        nextExpiryDate: null,
        automaticRenewal: true,
        renewalDurationMonths: 12,
        noticeDays: 60,
        today: '2026-08-21',
        state: ContractState::Active,
        plannedCessationDate: null,
        costCenterId: null,
        renewalWithoutCondition: false,
    );

    expect($deadline->nextExpiryDate)->toBeNull()
        ->and($deadline->noticeLimitDate)->toBeNull()
        ->and($deadline->daysUntilExpiry)->toBeNull()
        ->and($deadline->daysUntilNoticeLimit)->toBeNull();
});

it('subtracts calendar notice days exactly across leap-day and short-month boundaries', function (string $expiry, int $noticeDays, string $expected) {
    $deadline = ContractDeadline::derive(
        contractualStartDate: '2024-01-01',
        nextExpiryDate: $expiry,
        automaticRenewal: false,
        renewalDurationMonths: null,
        noticeDays: $noticeDays,
        today: '2024-01-01',
        state: ContractState::Active,
        plannedCessationDate: null,
        costCenterId: null,
        renewalWithoutCondition: false,
    );

    expect($deadline->noticeLimitDate)->toBe($expected);
})->with([
    ['2024-03-31', 31, '2024-02-29'],
    ['2025-03-31', 31, '2025-02-28'],
    ['2024-02-29', 30, '2024-01-30'],
]);
