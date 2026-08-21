<?php

use App\Domain\Contracts\ContractLifecycleRules;
use App\Domain\Contracts\ContractState;
use App\Domain\Contracts\ContractStateTimeline;

it('accepts only canonical lifecycle sequences and rejects duplicate effective dates', function () {
    $facts = [
        ['type' => 'activation', 'state_change_date' => '2026-01-01', 'annulled_at' => null],
        ['type' => 'cessation', 'state_change_date' => '2026-07-01', 'annulled_at' => null],
        ['type' => 'reactivation', 'state_change_date' => '2026-09-01', 'annulled_at' => null],
    ];

    expect(fn () => ContractLifecycleRules::validate('2026-01-01', $facts))->not->toThrow(Throwable::class)
        ->and(fn () => ContractLifecycleRules::validate('2026-01-01', [
            ...$facts,
            ['type' => 'cancellation', 'state_change_date' => '2026-09-01', 'annulled_at' => null],
        ]))->toThrow(DomainException::class);
});

it('ignores annulled future facts and validates a canonical replacement', function () {
    $facts = [
        ['type' => 'activation', 'state_change_date' => '2026-01-01', 'annulled_at' => null],
        ['type' => 'cessation', 'state_change_date' => '2027-01-01', 'annulled_at' => '2026-08-20T10:00:00Z'],
        ['type' => 'cessation', 'state_change_date' => '2027-07-01', 'annulled_at' => null],
    ];

    ContractLifecycleRules::validate('2026-01-01', $facts);

    expect(ContractStateTimeline::stateAtDate('2026-01-01', $facts, '2027-03-01'))->toBe(ContractState::Active)
        ->and(ContractStateTimeline::stateAtDate('2026-01-01', $facts, '2027-07-01'))->toBe(ContractState::Cessated);
});

it('projects non-renewed expiry without prematurely persisting a lifecycle fact', function () {
    $configurations = [[
        'id' => 1,
        'effective_from' => '2026-01-01',
        'automatic_renewal' => false,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => null,
    ]];

    expect(ContractStateTimeline::stateAtDate('2026-01-01', [], '2026-12-31', $configurations))->toBe(ContractState::Active)
        ->and(ContractStateTimeline::stateAtDate('2026-01-01', [], '2027-01-01', $configurations))->toBe(ContractState::Cessated);
});
