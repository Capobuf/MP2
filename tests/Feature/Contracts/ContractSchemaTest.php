<?php

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the complete forward S5 persistence surface', function () {
    expect(Schema::hasColumns('contracts', [
        'id', 'company_id', 'supplier_id', 'title', 'notes',
        'contractual_start_date', 'next_expiry_date', 'renewal_anchor_date',
        'automatic_renewal', 'renewal_duration_months', 'notice_days',
        'archived_at', 'revision',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('contract_renewal_configurations', [
            'id', 'company_id', 'contract_id', 'effective_from',
            'automatic_renewal', 'expiry_anchor_date', 'renewal_duration_months',
            'notice_days', 'created_by_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('contract_lifecycle_facts', [
            'id', 'company_id', 'contract_id', 'type', 'declared_contractual_date',
            'state_change_date', 'renewed_expiry_date', 'renewal_configuration_id',
            'reason', 'created_by_id', 'annulled_at', 'annulled_by_id',
            'annulment_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('contract_conditions', [
            'id', 'company_id', 'contract_id', 'cycle', 'attribution_mode',
            'amount', 'valid_from', 'valid_to', 'reason', 'created_by_id',
            'annulled_at', 'annulled_by_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('contract_exercise_classifications', [
            'id', 'company_id', 'contract_id', 'exercise_id', 'cost_center_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('project_contract_links', [
            'id', 'company_id', 'project_id', 'contract_id', 'note',
            'archived_at', 'revision',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('attachments', [
            'id', 'company_id', 'contract_id', 'expense_id', 'expense_line_id',
            'storage_disk', 'storage_path', 'original_name', 'media_type',
            'size_bytes', 'sha256', 'uploaded_by_id', 'detached_at',
            'detached_by_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('expenses', ['contract_id', 'origin']))->toBeTrue();
});

it('enforces exclusive Expense and Attachment owners at database level', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();

    expect(fn () => Expense::factory()->forExercise($exercise)->create([
        'project_id' => $project->id,
        'contract_id' => $contract->id,
        'direct_cost_center_id' => null,
    ]))->toThrow(QueryException::class)
        ->and(fn () => Attachment::factory()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'expense_id' => $expense->id,
        ]))->toThrow(QueryException::class);
});

it('enforces company references and active fact uniqueness in the database', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $actor = User::factory()->create();
    $supplierB = Supplier::factory()->for($companyB)->create();
    $contractA = Contract::factory()->for($companyA)->create();
    $exerciseA = Exercise::factory()->for($companyA)->create();
    $projectA = Project::factory()->for($companyA)->create();

    expect(fn () => Contract::factory()->for($companyA)->create(['supplier_id' => $supplierB->id]))
        ->toThrow(QueryException::class);

    ContractLifecycleFact::factory()->forContract($contractA)->create([
        'state_change_date' => '2027-01-01',
        'declared_contractual_date' => '2027-01-01',
        'created_by_id' => $actor->id,
    ]);
    expect(fn () => ContractLifecycleFact::factory()->forContract($contractA)->create([
        'state_change_date' => '2027-01-01',
        'declared_contractual_date' => '2027-01-01',
        'created_by_id' => $actor->id,
    ]))->toThrow(QueryException::class);

    ContractExerciseClassification::factory()->forContractAndExercise($contractA, $exerciseA)->create();
    expect(fn () => ContractExerciseClassification::factory()->forContractAndExercise($contractA, $exerciseA)->create())
        ->toThrow(QueryException::class);

    ProjectContractLink::factory()->forProjectAndContract($projectA, $contractA)->create();
    expect(fn () => ProjectContractLink::factory()->forProjectAndContract($projectA, $contractA)->create())
        ->toThrow(QueryException::class);
});

it('rejects ordinary physical deletion of every S5 identity', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $configuration = ContractRenewalConfiguration::factory()->forContract($contract)->create(['created_by_id' => $actor->id]);
    $fact = ContractLifecycleFact::factory()->forContract($contract)->create(['created_by_id' => $actor->id]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['created_by_id' => $actor->id]);
    $classification = ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    $link = ProjectContractLink::factory()->forProjectAndContract($project, $contract)->create();
    $attachment = Attachment::factory()->forContract($contract)->create(['uploaded_by_id' => $actor->id]);

    foreach ([$configuration, $fact, $condition, $classification, $link, $attachment, $contract] as $record) {
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }
});
