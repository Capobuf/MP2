<?php

use App\Domain\Proposals\BudgetPayloadGuard;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadinessReason;
use App\Models\Proposal;
use App\Models\ProposalAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('keeps S8 through S11 and directed replacement outside the S7 action vocabulary', function (): void {
    $actions = collect(ProposalActionType::cases())->map->value->all();

    expect($actions)->not->toContain('carryover', 'reprogramming', 'closing', 'late_correction', 'historical_annotation', 'forecast', 'replace_project_contract')
        ->and(ProposalReadinessReason::tryFrom('invalid_action'))->toBeNull();
});

it('rejects forecast actual field merge and physical deletion paths', function (): void {
    foreach (['forecast_amount', 'actual_total'] as $key) {
        expect(fn () => BudgetPayloadGuard::assertPlanOnly([$key => '1.00']))->toThrow(ValidationException::class);
    }
    expect(ProposalActionType::tryFrom('field_merge'))->toBeNull();

    $proposal = Proposal::factory()->create();
    $action = ProposalAction::factory()->for($proposal)->create(['company_id' => $proposal->company_id]);
    expect(fn () => $proposal->delete())->toThrow(LogicException::class)
        ->and(fn () => $action->delete())->toThrow(LogicException::class);
});
