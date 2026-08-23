<?php

use App\Actions\Operations\CreateProjectTransition;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectDeferralValues;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('proves INV-28.11 and INV-28.13 from live Project values including both negative-Actual cases', function (string $estimate, string $actual, string $residual, string $maximum): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    if ($estimate !== '0.00') {
        ExpenseLine::factory()->for($expense)->create(['amount' => $estimate]);
    }
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => $actual, 'note' => str_starts_with($actual, '-') ? 'Rimborso documentato' : null]);
    $totals = $project->annualTotals()[$exercise->id];

    expect($totals['allocation'])->toBe($estimate)
        ->and($totals['actual'])->toBe($actual)
        ->and(ProjectDeferralValues::residual($totals['allocation'], $totals['actual']))->toBe($residual)
        ->and(ProjectDeferralValues::maximumTransferable($totals['allocation'], $totals['actual']))->toBe($maximum);
})->with([
    'ordinary' => ['10000.00', '6000.00', '4000.00', '4000.00'],
    'negative Actual capped at allocation' => ['10000.00', '-1000.00', '11000.00', '10000.00'],
    'zero allocation with negative Actual' => ['0.00', '-1000.00', '1000.00', '0.00'],
]);

it('counts received Carryover once but permits Reprogramming only from selected reducible Estimates', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2025-01-01']);
    ProjectDeferral::factory()->carryover('100.00')->create([
        'company_id' => $company->id, 'project_id' => $project->id,
        'source_exercise_id' => $previous->id, 'destination_exercise_id' => $source->id,
    ]);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '20.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();

    expect($project->annualTotals()[$source->id]['allocation'])->toBe('120.00')
        ->and($source->allocation())->toBe('120.00')
        ->and(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
            'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
            'mode' => 'reprogramming', 'reprogrammed_amount' => '30.00',
            'source_estimate_reductions' => [['source_line_id' => $line->id, 'reduction_amount' => '30.00']],
        ], 'Oltre le Stime riducibili', (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});

it('proves INV-28.16 by blocking terminal live state while preserving the outgoing decision', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageOperations]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $deferral = ProjectDeferral::factory()->carryover('10.00')->create([
        'company_id' => $company->id, 'project_id' => $project->id,
        'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
    ]);

    expect(fn () => app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'open', 'to_state' => 'cancelled', 'effective_date' => '2026-11-01', 'reason' => 'Annullamento',
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and($deferral->refresh()->mode->value)->toBe('carryover')
        ->and($project->transitions()->count())->toBe(0);
});
