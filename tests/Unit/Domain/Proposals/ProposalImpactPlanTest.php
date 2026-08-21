<?php

use App\Domain\Proposals\ProposalImpactPlan;

it('sums plan allocations exactly from active estimate lines', function (): void {
    $result = ['estimate_lines' => [['amount' => '10.10', 'annulled' => false], ['amount' => '2.20', 'annulled' => true], ['amount' => '0.90', 'annulled' => false]]];
    expect(ProposalImpactPlan::allocation($result))->toBe('11.00');
});
