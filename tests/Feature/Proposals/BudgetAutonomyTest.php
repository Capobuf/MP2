<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Domain\Proposals\ProposalActionType;
use App\Models\Attachment;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('keeps the approved Budget unchanged after the live source changes', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $approver = User::factory()->create();

    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $approver,
            'permissions' => $capability,
        ]);
    }

    $expense = Expense::factory()->forExercise($exercise)->create([
        'description' => 'Canone originario',
    ]);
    $line = ExpenseLine::factory()->for($expense)->create([
        'type' => 'estimate',
        'amount' => '20.00',
    ]);
    $proposal = app(InitializeProposal::class)->execute(
        $approver,
        $company,
        $exercise,
        (string) Str::uuid(),
    );
    app(PlanExpense::class)->execute(
        $approver,
        $proposal,
        $proposal->items->sole(),
        ProposalActionType::SetExpenseEstimates,
        ['estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => $line->id,
            'amount' => '25.00',
            'note' => 'Importo approvato',
            'annulled' => false,
        ]]],
        null,
        (string) Str::uuid(),
        0,
    );

    $budget = app(ApproveProposal::class)->execute(
        $approver,
        $proposal->refresh(),
        (string) Str::uuid(),
        ['external_subject' => 'Direzione', 'reason' => 'Approvazione iniziale'],
    );
    $storedBudget = $budget->fresh(['rows', 'evidence'])->toArray();

    $expense->update([
        'description' => 'Canone modificato dopo approvazione',
        'revision' => $expense->revision + 1,
    ]);
    $line->update(['amount' => '999.00']);

    expect($budget->fresh(['rows', 'evidence'])->toArray())->toBe($storedBudget)
        ->and(BudgetSourceRow::query()->sole()->label)->toBe('Canone originario')
        ->and(BudgetSourceRow::query()->sole()->approved_allocation)->toBe('25.00');
});

it('retains materialized Project Contract classification condition and evidence values after live archive and detachment', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $creator = User::factory()->create();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create([
        'created_by_id' => $creator->id,
        'status' => 'approved',
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'approved_by_id' => $creator->id,
        'total_approved_allocation' => '300.00',
    ]);
    $project = Project::factory()->for($company)->create(['title' => 'Progetto storico']);
    $contract = Contract::factory()->for($company)->create(['title' => 'Contratto storico']);
    $projectClassification = ProjectExerciseClassification::factory()
        ->forProjectAndExercise($project, $exercise)
        ->create();
    $contractClassification = ContractExerciseClassification::factory()
        ->forContractAndExercise($contract, $exercise)
        ->create();
    $condition = ContractCondition::factory()->forContract($contract)->create(['amount' => '100.00']);
    $attachment = Attachment::factory()->forContract($contract)->create([
        'original_name' => 'delibera.pdf',
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'source_type' => 'project',
        'origin_id' => $project->id,
        'origin_key' => $project->originKey(),
        'label' => 'Progetto storico',
        'approved_allocation' => '200.00',
        'detail' => ['plan' => ['classification' => 'Centro storico'], 'actions' => []],
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'source_type' => 'contract',
        'origin_id' => $contract->id,
        'origin_key' => $contract->originKey(),
        'label' => 'Contratto storico',
        'approved_allocation' => '100.00',
        'detail' => ['plan' => ['condition_amount' => '100.00'], 'actions' => []],
    ]);
    BudgetEvidence::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'attachment_id' => $attachment->id,
        'storage_disk' => $attachment->storage_disk,
        'storage_path' => $attachment->storage_path,
        'original_name' => $attachment->original_name,
        'media_type' => $attachment->media_type,
        'size_bytes' => $attachment->size_bytes,
        'sha256' => $attachment->sha256,
    ]);
    $storedBudget = $budget->fresh(['rows', 'evidence'])->toArray();
    $newCostCenter = CostCenter::factory()->for($company)->create();

    $project->update(['title' => 'Progetto rinominato', 'archived_at' => now(), 'revision' => 1]);
    $contract->update(['title' => 'Contratto rinominato', 'archived_at' => now(), 'revision' => 1]);
    $projectClassification->update(['cost_center_id' => $newCostCenter->id]);
    $contractClassification->update(['cost_center_id' => $newCostCenter->id]);
    $condition->update(['amount' => '900.00']);
    $attachment->update(['detached_at' => now(), 'detached_by_id' => $creator->id]);

    expect($budget->fresh(['rows', 'evidence'])->toArray())->toBe($storedBudget)
        ->and($budget->rows()->orderBy('label')->pluck('label')->all())->toBe(['Contratto storico', 'Progetto storico'])
        ->and($budget->evidence()->sole()->original_name)->toBe('delibera.pdf');
});
