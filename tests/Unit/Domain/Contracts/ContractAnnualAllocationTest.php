<?php

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycle;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractState;

it('enumerates recurrences from the original anchor without propagating month-end adjustment', function () {
    $cycles = ContractCycle::enumerate(
        conditionId: 7,
        cycle: ContractCycleType::Monthly,
        attributionMode: ContractAttributionMode::CycleStart,
        amount: '10.25',
        validFrom: '2024-01-31',
        validTo: '2024-04-30',
        through: '2024-05-01',
    );

    expect(array_map(fn (ContractCycle $cycle): string => $cycle->start->toDateString(), $cycles))
        ->toBe(['2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30']);
});

it('attributes exact full cycles to start or next cycle start and returns composition', function () {
    $conditions = [
        [
            'id' => 10,
            'cycle' => 'quarterly',
            'attribution_mode' => 'cycle_end',
            'amount' => '100.15',
            'valid_from' => '2025-10-31',
            'valid_to' => '2026-12-31',
            'annulled_at' => null,
        ],
    ];

    $allocation = ContractAnnualAllocation::forYear(
        conditions: $conditions,
        year: 2026,
        stateAt: fn (string $date): ContractState => $date <= '2026-07-31'
            ? ContractState::Active
            : ContractState::Cessated,
    );

    expect($allocation->amount)->toBe('400.60')
        ->and(array_column($allocation->composition, 'attribution_date'))
        ->toBe(['2026-01-31', '2026-04-30', '2026-07-31', '2026-10-31']);
});

it('does not invent allocation for a gap or an inactive cycle start', function () {
    $allocation = ContractAnnualAllocation::forYear(
        conditions: [[
            'id' => 11,
            'cycle' => 'annual',
            'attribution_mode' => 'cycle_start',
            'amount' => '999.99',
            'valid_from' => '2026-02-01',
            'valid_to' => '2026-12-31',
            'annulled_at' => null,
        ]],
        year: 2026,
        stateAt: fn (): ContractState => ContractState::Planned,
    );

    expect($allocation->amount)->toBe('0.00')->and($allocation->composition)->toBe([]);
});

it('keeps 28 29 30 and 31 anchors across leap years and year boundaries', function (string $anchor, array $expected) {
    $cycles = ContractCycle::enumerate(1, ContractCycleType::Monthly, ContractAttributionMode::CycleStart, '1.00', $anchor, null, '2024-04-30');

    expect(array_map(fn (ContractCycle $cycle): string => $cycle->start->toDateString(), $cycles))->toBe($expected);
})->with([
    ['2024-01-28', ['2024-01-28', '2024-02-28', '2024-03-28', '2024-04-28']],
    ['2024-01-29', ['2024-01-29', '2024-02-29', '2024-03-29', '2024-04-29']],
    ['2024-01-30', ['2024-01-30', '2024-02-29', '2024-03-30', '2024-04-30']],
    ['2024-01-31', ['2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30']],
    ['2023-12-31', ['2023-12-31', '2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30']],
]);

it('keeps a full cycle whose end attribution follows cessation', function () {
    $allocation = ContractAnnualAllocation::forYear([[
        'id' => 20,
        'cycle' => 'quarterly',
        'attribution_mode' => 'cycle_end',
        'amount' => '75.00',
        'valid_from' => '2026-01-31',
        'valid_to' => '2026-01-31',
        'annulled_at' => null,
    ]], 2026, fn (string $date): ContractState => $date === '2026-01-31' ? ContractState::Active : ContractState::Cessated);

    expect($allocation->amount)->toBe('75.00')
        ->and($allocation->composition[0]['attribution_date'])->toBe('2026-04-30');
});
