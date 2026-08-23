<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProposalRelation;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionReplay;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function replayFixture(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::ManageProposals,
    ]);

    return [$company, $exercise, $actor];
}

it('replays active expense project and contract decisions from fresh whole-source baselines', function (): void {
    [$company, $exercise, $actor] = replayFixture();
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01']);
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01']);
    $costCenter = CostCenter::factory()->for($company)->create();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    $expenseItem = $proposal->items()->where('expense_id', $expense->id)->sole();
    app(PlanExpense::class)->execute($actor, $proposal, $expenseItem, ProposalActionType::SetExpenseEstimates, [
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => $line->id,
            'amount' => '8.00',
            'note' => null,
            'annulled' => false,
        ]],
    ], null, (string) Str::uuid(), 0);

    $projectItem = $proposal->items()->where('project_id', $project->id)->sole();
    app(PlanProject::class)->execute($actor, $proposal->refresh(), $projectItem, ProposalActionType::SetProjectCostCenter, [
        'exercise_id' => $exercise->id,
        'cost_center_id' => $costCenter->id,
    ], null, (string) Str::uuid(), 1);

    $contractItem = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanContract::class)->execute($actor, $proposal->refresh(), $contractItem, ProposalActionType::SetContractRenewal, [
        'effective_from' => '2026-09-01',
        'expiry_anchor_date' => '2026-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
        'notice_days' => 30,
    ], null, (string) Str::uuid(), 2);

    $replay = app(ProposalActionReplay::class);
    $expenseResult = $replay->replay($expenseItem->fresh(), ProposalSourceSnapshot::expense($expense->fresh()));
    $projectResult = $replay->replay($projectItem->fresh(), ProposalSourceSnapshot::project($project->fresh(), $exercise->id));
    $contractResult = $replay->replay($contractItem->fresh(), ProposalSourceSnapshot::contract($contract->fresh(), $exercise->id));

    expect(data_get($expenseResult, 'estimate_lines.0.amount'))->toBe('8.00')
        ->and($projectResult['cost_center_id'])->toBe($costCenter->id)
        ->and($contractResult['renewal_duration_months'])->toBe(12);
});

it('selects touching relation decisions and never replays withdrawn decisions', function (): void {
    [$company, $exercise, $actor] = replayFixture();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $project = ProposalItem::factory()->for($proposal)->create(['company_id' => $company->id, 'source_type' => 'project']);
    $contract = ProposalItem::factory()->for($proposal)->create(['company_id' => $company->id, 'source_type' => 'contract']);
    $relation = app(PlanProposalRelation::class)->execute($actor, $proposal, [
        'project_item_id' => $project->proposal_item_id,
        'contract_item_id' => $contract->proposal_item_id,
    ], (string) Str::uuid(), 0);

    $replay = app(ProposalActionReplay::class);

    expect($replay->touchingActions($project, $proposal->fresh()->actionHistory)->pluck('id')->all())->toBe([$relation->id]);

    $relation->update([
        'status' => 'withdrawn',
        'withdrawn_by_id' => $actor->id,
        'withdrawn_at' => now(),
        'withdraw_operation_id' => (string) Str::uuid(),
        'withdraw_reason' => 'Rimossa',
    ]);

    expect($replay->touchingActions($project->fresh(), $proposal->fresh()->actionHistory)->all())->toBe([]);
});
