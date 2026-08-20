<?php

use App\Domain\Contracts\ContractState;
use App\Domain\Contracts\ContractStateTimeline;
use Carbon\CarbonImmutable;

it('derives planned active cessated and cancelled states at an explicit date', function () {
    $facts = [
        ['type' => 'activation', 'state_change_date' => '2026-02-01', 'annulled_at' => null],
        ['type' => 'cessation', 'state_change_date' => '2026-07-01', 'annulled_at' => null],
        ['type' => 'reactivation', 'state_change_date' => '2026-09-01', 'annulled_at' => null],
        ['type' => 'cancellation', 'state_change_date' => '2027-01-01', 'annulled_at' => null],
    ];

    expect(ContractStateTimeline::stateAtDate('2026-02-01', $facts, '2026-01-31'))->toBe(ContractState::Planned)
        ->and(ContractStateTimeline::stateAtDate('2026-02-01', $facts, '2026-06-30'))->toBe(ContractState::Active)
        ->and(ContractStateTimeline::stateAtDate('2026-02-01', $facts, '2026-08-31'))->toBe(ContractState::Cessated)
        ->and(ContractStateTimeline::stateAtDate('2026-02-01', $facts, '2026-12-31'))->toBe(ContractState::Active)
        ->and(ContractStateTimeline::stateAtDate('2026-02-01', $facts, '2027-01-01'))->toBe(ContractState::Cancelled);
});

it('ignores annulled facts and uses canonical company-local annual reference dates', function () {
    $facts = [
        ['type' => 'cessation', 'state_change_date' => '2026-05-01', 'annulled_at' => '2026-04-01 10:00:00'],
    ];
    $today = CarbonImmutable::parse('2026-08-20', 'Europe/Rome');

    expect(ContractStateTimeline::stateAtDate('2026-01-01', $facts, '2026-12-31'))->toBe(ContractState::Active)
        ->and(ContractStateTimeline::referenceDateForExercise(2025, $today)->toDateString())->toBe('2025-12-31')
        ->and(ContractStateTimeline::referenceDateForExercise(2026, $today)->toDateString())->toBe('2026-08-20')
        ->and(ContractStateTimeline::referenceDateForExercise(2027, $today)->toDateString())->toBe('2027-01-01');
});
