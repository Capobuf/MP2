<?php

use App\Domain\Proposals\ContractPlan;
use App\Domain\Proposals\ProposalActionType;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('requires explicit reconfirmation when contract economic effective date changes', function (): void {
    $item = ProposalItem::factory()->create(['source_type' => 'contract']);
    $payload = ['condition_id' => 1, 'amount' => '12.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'requested_date' => '2026-01-01', 'confirmed_effective_date' => '2026-02-01', 'minimum_date' => '2026-02-01', 'effective_date' => '2026-02-01', 'no_prorata' => false, 'effective_date_confirmed' => true];
    expect(fn () => ContractPlan::apply($item, ProposalActionType::ChangeContractEconomics, $payload))->toThrow(ValidationException::class);
});
