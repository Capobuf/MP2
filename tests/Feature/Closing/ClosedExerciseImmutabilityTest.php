<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Actions\Operations\ProcessContractRenewals;
use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExerciseStatus;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('prevents ordinary historical mutation after Closing', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise, Capability::ManageOperations, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '5.00']);
    ProjectDeferral::factory()->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '3.00',
        'carryover_state' => 'provisional',
    ]);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $source, [
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '3.00',
            'reason' => 'Consolidamento finale',
        ]],
    ]);
    app(CloseExercise::class)->execute($actor, $source, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());

    $deferral = ProjectDeferral::query()->where('project_id', $project->id)->sole();

    expect(fn () => $source->refresh()->update(['status' => ExerciseStatus::Open]))->toThrow(LogicException::class)
        ->and($actor->can('update', $expense->refresh()))->toBeFalse()
        ->and($actor->can('update', $estimate->refresh()))->toBeFalse()
        ->and(fn () => $deferral->update(['carryover_amount' => '2.00']))->toThrow(ValidationException::class)
        ->and(fn () => ProjectTransition::query()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'from_state' => 'open',
            'to_state' => 'closed',
            'effective_date' => '2025-12-31',
            'reason' => 'Tentativo retroattivo',
            'created_by_id' => $actor->id,
        ]))->toThrow(ValidationException::class)
        ->and(fn () => app(InitializeProposal::class)->execute($actor, $company, $source->refresh(), (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('rejects moving Contract and Project facts out of Closed history', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $closed = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2025-01-01',
        'automatic_renewal' => false,
        'expiry_anchor_date' => null,
        'renewal_duration_months' => null,
    ]);
    $condition = ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2025-01-01',
        'valid_to' => null,
        'amount' => '10.00',
    ]);
    $fact = ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation',
        'declared_contractual_date' => '2025-06-30',
        'state_change_date' => '2025-07-01',
        'reason' => 'Cessazione originaria',
    ]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $transition = ProjectTransition::factory()->for($project)->create([
        'company_id' => $company->id,
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2025-08-01',
        'reason' => 'Chiusura originaria',
    ]);
    closeExerciseFixture($closed, $actor);

    expect(fn () => $condition->update(['valid_to' => '2025-06-30']))->toThrow(ValidationException::class)
        ->and(fn () => $fact->update(['state_change_date' => '2026-07-01']))->toThrow(ValidationException::class)
        ->and(fn () => $transition->update(['effective_date' => '2026-08-01']))->toThrow(ValidationException::class)
        ->and($condition->refresh()->validTo())->toBeNull()
        ->and($fact->refresh()->stateChangeDate()?->toDateString())->toBe('2025-07-01')
        ->and($transition->refresh()->effectiveDate()->toDateString())->toBe('2025-08-01');
});

it('blocks ordinary non-renewal processing that would rewrite a Closed year', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::ManageOperations,
    ]);
    $closed = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => '2025-06-30',
        'renewal_anchor_date' => '2025-06-30',
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2025-01-01',
        'automatic_renewal' => false,
        'expiry_anchor_date' => '2025-06-30',
        'renewal_duration_months' => null,
    ]);
    $condition = ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2025-01-01',
        'valid_to' => null,
        'amount' => '10.00',
    ]);
    closeExerciseFixture($closed, $actor);

    expect(fn () => app(ProcessContractRenewals::class)->execute($actor, $contract, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(ContractLifecycleFact::query()->where('contract_id', $contract->id)->where('type', 'expiry_cessation')->exists())->toBeFalse()
        ->and($condition->refresh()->validTo())->toBeNull()
        ->and($contract->refresh()->nextExpiryDate()?->toDateString())->toBe('2025-06-30');
});
