<?php

use App\Domain\Contracts\ContractEconomicChangePlan;
use App\Domain\Contracts\ContractImpactFingerprint;

it('calculates the minimum and first anchored cycle boundary without prorata', function () {
    $boundary = ContractEconomicChangePlan::boundary(
        currentTerms: ['cycle' => 'quarterly', 'valid_from' => '2026-01-31'],
        requestedDate: '2026-08-21',
        confirmationDate: '2026-08-21',
        lastApplicableDate: null,
    );

    expect($boundary)->toMatchArray([
        'requested_date' => '2026-08-21',
        'minimum_date' => '2026-09-01',
        'effective_date' => '2026-10-31',
        'no_prorata' => true,
        'future_replacement' => false,
    ])->and($boundary['delay_reason'])->toContain('ciclo');
});

it('keeps the original anchor when replacing a future condition whose first cycle has not begun', function () {
    $boundary = ContractEconomicChangePlan::boundary(
        currentTerms: ['cycle' => 'annual', 'valid_from' => '2027-01-15'],
        requestedDate: '2026-10-01',
        confirmationDate: '2026-08-21',
        lastApplicableDate: null,
    );

    expect($boundary['minimum_date'])->toBe('2026-09-01')
        ->and($boundary['effective_date'])->toBe('2027-01-15')
        ->and($boundary['future_replacement'])->toBeTrue()
        ->and($boundary['no_prorata'])->toBeTrue();
});

it('blocks a change when no complete cycle boundary exists before the terminal date', function () {
    expect(fn () => ContractEconomicChangePlan::boundary(
        currentTerms: ['cycle' => 'annual', 'valid_from' => '2026-01-31'],
        requestedDate: '2026-08-21',
        confirmationDate: '2026-08-21',
        lastApplicableDate: '2026-12-31',
    ))->toThrow(DomainException::class);
});

it('creates a canonical immutable fingerprint independent of associative key order', function () {
    $left = ContractImpactFingerprint::make([
        'contract_id' => 4,
        'terms' => ['amount' => '10.00', 'cycle' => 'monthly'],
        'exercise_revisions' => ['8' => 3, '2' => 1],
    ]);
    $same = ContractImpactFingerprint::make([
        'exercise_revisions' => ['2' => 1, '8' => 3],
        'terms' => ['cycle' => 'monthly', 'amount' => '10.00'],
        'contract_id' => 4,
    ]);
    $changed = ContractImpactFingerprint::make([
        'contract_id' => 4,
        'terms' => ['amount' => '11.00', 'cycle' => 'monthly'],
        'exercise_revisions' => ['2' => 1, '8' => 3],
    ]);

    expect($left)->toBe($same)->not->toBe($changed);
});
