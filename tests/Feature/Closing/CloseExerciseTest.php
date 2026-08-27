<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Actions\Operations\CreateExercise as CreateExerciseAction;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function s9CloseActor(Company $company): User
{
    $user = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }

    return $user;
}

function s9CarryoverProject(Company $company, Exercise $exercise): Project
{
    $project = Project::factory()->for($company)->create([
        'title' => 'Carryover at Closing',
        'initial_state' => 'open',
        'initial_effective_date' => $exercise->year.'-01-01',
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['description' => 'Closing source plan']);
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);

    return $project;
}

function s9PreparedCarryover(User $actor, Exercise $exercise, Project $project): array
{
    return app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'create_next_exercise' => true,
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '50.00',
            'reason' => 'Riporto deciso alla Chiusura',
        ]],
    ]);
}

it('closes without a Budget, creates N+1 and consolidates Carryover exactly once', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create(['name' => 'S9 Closing Company']);
    $actor = s9CloseActor($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $project = s9CarryoverProject($company, $exercise);
    $prepared = s9PreparedCarryover($actor, $exercise, $project);
    $operationId = (string) Str::uuid();

    $input = [...$prepared['input'], 'review_fingerprint' => $prepared['execution_fingerprint'], 'warnings_acknowledged' => true, 'confirmed' => true];
    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, $input, $operationId);
    $next = Exercise::query()->where('company_id', $company->id)->where('year', 2026)->sole();
    $deferral = ProjectDeferral::query()->where('project_id', $project->id)->where('source_exercise_id', $exercise->id)->sole();
    $projectRow = $snapshot->rows()->where('origin_key', $project->originKey())->sole();

    expect($exercise->refresh()->isOpen())->toBeFalse()
        ->and($snapshot->initial_budget_id)->toBeNull()
        ->and($snapshot->current_budget_id)->toBeNull()
        ->and($snapshot->next_exercise_disposition)->toBe('created')
        ->and($snapshot->next_exercise_id)->toBe($next->id)
        ->and($snapshot->total_final_allocation)->toBe('100.00')
        ->and($snapshot->total_closing_actual)->toBe('40.00')
        ->and($snapshot->total_consolidated_carryover)->toBe('50.00')
        ->and($deferral->carryover_state)->toBe('consolidated')
        ->and($deferral->carryover_amount)->toBe('50.00')
        ->and($next->allocation())->toBe('50.00')
        ->and($projectRow->final_allocation)->toBe('100.00');

    $retry = app(CloseExercise::class)->execute($actor, $exercise->refresh(), $input, $operationId);
    expect($retry->id)->toBe($snapshot->id)
        ->and(ClosingSnapshot::query()->where('exercise_id', $exercise->id)->count())->toBe(1)
        ->and(Exercise::query()->where('company_id', $company->id)->where('year', 2026)->count())->toBe(1);
});

it('records the intentional non-creation of N+1 without changing the Tenant lifecycle', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9CloseActor($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['create_next_exercise' => false, 'projects' => []]);

    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'confirmed' => true,
        'warnings_acknowledged' => false,
    ], (string) Str::uuid());

    expect(Exercise::query()
        ->where('company_id', $company->id)
        ->where('year', 2026)
        ->exists())->toBeFalse();

    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::ManageOperations,
    ]);
    $laterExercise = app(CreateExerciseAction::class)->execute(
        $actor,
        $company,
        ['year' => 2026],
        (string) Str::uuid(),
    );

    expect($snapshot->next_exercise_disposition)->toBe('not_created')
        ->and($snapshot->next_exercise_id)->toBeNull()
        ->and($company->tenantCompany->status)->toBe(TenantCompanyStatus::Active)
        ->and($laterExercise->year)->toBe(2026)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::NextExerciseNotCreated->value)->exists())->toBeTrue();
});

it('initializes a created N+1 with inherited classifications and Contract Estimates only', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9CloseActor($company);
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $costCenter = CostCenter::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $source)->create([
        'cost_center_id' => $costCenter->id,
    ]);
    $projectExpense = Expense::factory()->forExercise($source)->for($project)->create();
    ExpenseLine::factory()->for($projectExpense)->create(['amount' => '30.00']);
    ExpenseLine::factory()->for($projectExpense)->actual()->create(['amount' => '10.00']);
    $standalone = Expense::factory()->forExercise($source)->create([
        'direct_cost_center_id' => $costCenter->id,
    ]);
    ExpenseLine::factory()->for($standalone)->create(['amount' => '5.00']);

    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2025-01-01',
        'automatic_renewal' => false,
        'expiry_anchor_date' => null,
        'renewal_duration_months' => null,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2025-01-01',
        'valid_to' => null,
        'cycle' => 'monthly',
        'amount' => '10.00',
    ]);
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $source)->create([
        'cost_center_id' => $costCenter->id,
    ]);

    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $source, [
        'create_next_exercise' => true,
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'none',
        ]],
    ]);
    app(CloseExercise::class)->execute($actor, $source, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());
    $next = Exercise::query()->where('company_id', $company->id)->where('year', 2026)->sole();

    expect(ProjectExerciseClassification::query()->where('project_id', $project->id)->where('exercise_id', $next->id)->sole()->cost_center_id)->toBe($costCenter->id)
        ->and(ContractExerciseClassification::query()->where('contract_id', $contract->id)->where('exercise_id', $next->id)->sole()->cost_center_id)->toBe($costCenter->id)
        ->and(Expense::query()->where('contract_id', $contract->id)->where('exercise_id', $next->id)->where('origin', 'system')->sole()->allocation())->toBe('120.00')
        ->and(Expense::query()->where('project_id', $project->id)->where('exercise_id', $next->id)->exists())->toBeFalse()
        ->and(Expense::query()->where('exercise_id', $next->id)->whereNull('project_id')->whereNull('contract_id')->exists())->toBeFalse()
        ->and(ExpenseLine::query()->whereHas('expense', fn ($query) => $query->where('exercise_id', $next->id))->where('type', 'actual')->exists())->toBeFalse()
        ->and(BudgetSnapshot::query()->where('exercise_id', $next->id)->exists())->toBeFalse();
});

it('consolidates an explicit Carryover delta without rewriting N+1 Budget', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9CloseActor($company);
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'capability' => $capability,
        ]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = s9CarryoverProject($company, $source);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();
    app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '30.00',
    ], 'Riporto provvisorio approvato', (string) Str::uuid(), 0);
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $budgetRow = BudgetSourceRow::query()
        ->where('budget_snapshot_id', $budget->id)
        ->where('origin_key', $project->originKey())
        ->sole();
    $budgetState = $budget->fresh()->getRawOriginal();
    $budgetRowState = $budgetRow->fresh()->getRawOriginal();
    $draft = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());

    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $source, [
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '35.00',
            'reason' => 'Riporto consolidato esplicito',
        ]],
    ]);
    $reprepared = app(PrepareExerciseClosing::class)->execute($actor, $source, $prepared['input']);
    expect($reprepared['execution_fingerprint'])->toBe($prepared['execution_fingerprint']);
    $snapshot = app(CloseExercise::class)->execute($actor, $source, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());

    $deferral = ProjectDeferral::query()->where('project_id', $project->id)->sole();
    expect($destination->refresh()->allocation())->toBe('35.00')
        ->and($deferral->carryover_amount)->toBe('35.00')
        ->and($deferral->carryover_state)->toBe('consolidated')
        ->and($budget->fresh()->getRawOriginal())->toBe($budgetState)
        ->and($budgetRow->fresh()->getRawOriginal())->toBe($budgetRowState)
        ->and($draft->items()->where('project_id', $project->id)->sole()->readiness_state->value)->toBe('to_realign')
        ->and($snapshot->total_consolidated_carryover)->toBe('35.00')
        ->and($snapshot->next_exercise_disposition)->toBe('already_existed');
});
