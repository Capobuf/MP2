<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function carryoverApprovalFixture(string $amount = '50.00'): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();
    app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => $amount,
    ], 'Riporto provvisorio', (string) Str::uuid(), 0);

    return compact('actor', 'company', 'source', 'destination', 'project', 'expense', 'estimate', 'proposal', 'item');
}

it('approves provisional Carryover once without changing source Estimates', function (): void {
    extract(carryoverApprovalFixture());
    $operation = (string) Str::uuid();
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operation);
    $retry = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operation);
    $deferral = ProjectDeferral::query()->sole();
    $row = BudgetSourceRow::query()->where('source_type', 'project')->sole();

    expect($retry->is($budget))->toBeTrue()
        ->and($estimate->refresh()->amount)->toBe('100.00')
        ->and($source->allocation())->toBe('100.00')
        ->and($destination->allocation())->toBe('50.00')
        ->and($project->refresh()->annualTotals()[$destination->id]['allocation'])->toBe('50.00')
        ->and($deferral->mode->value)->toBe('carryover')
        ->and($deferral->carryover_amount)->toBe('50.00')
        ->and($deferral->carryover_state)->toBe('provisional')
        ->and($row->approved_estimates)->toBe('0.00')
        ->and($row->approved_carryover)->toBe('50.00')
        ->and($row->approved_allocation)->toBe('50.00')
        ->and(data_get($row->detail, 'project.deferral_mode'))->toBe('carryover')
        ->and(ProjectDeferral::query()->count())->toBe(1)
        ->and(BudgetSnapshot::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operation)->where('event_type', AuditEventType::ProjectDeferralChanged)->count())->toBe(1);
});

it('rolls back Carryover state and Budget when approval fails after live apply', function (): void {
    extract(carryoverApprovalFixture());
    $operation = (string) Str::uuid();

    expect(fn () => app(ApproveProposal::class)->execute(
        $actor,
        $proposal->refresh(),
        $operation,
        checkpoint: fn (string $stage) => $stage === 'after_project_deferral' ? throw new RuntimeException('failure') : null,
    ))->toThrow(RuntimeException::class);

    expect(ProjectDeferral::query()->count())->toBe(0)
        ->and($destination->allocation())->toBe('0.00')
        ->and($estimate->refresh()->amount)->toBe('100.00')
        ->and(BudgetSnapshot::query()->count())->toBe(0)
        ->and($proposal->refresh()->status->value)->toBe('draft');
});

it('blocks approval after source facts make the planned Carryover stale', function (): void {
    extract(carryoverApprovalFixture());
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);
    $project->increment('revision');

    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    expect(ProjectDeferral::query()->count())->toBe(0)
        ->and(BudgetSnapshot::query()->count())->toBe(0)
        ->and($estimate->refresh()->amount)->toBe('100.00');
});

it('requires approva_budget again when applying the S8 decision', function (): void {
    extract(carryoverApprovalFixture());
    CompanyCapability::query()->where('company_id', $company->id)->where('user_id', $actor->id)->where('capability', Capability::ApproveBudget->value)->delete();

    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    expect(ProjectDeferral::query()->count())->toBe(0)
        ->and(BudgetSnapshot::query()->count())->toBe(0)
        ->and($estimate->refresh()->amount)->toBe('100.00');
});
