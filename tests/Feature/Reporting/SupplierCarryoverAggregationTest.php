<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportAggregator;
use App\Domain\Reporting\ReportDefinition;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('aggregates received carryover by Supplier while retaining outgoing carryover', function (): void {
    CarbonImmutable::setTestNow('2026-09-07 10:00:00 Europe/Rome');
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $sourceExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destinationExercise = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2026-01-01',
    ]);
    $expense = Expense::factory()->forExercise($sourceExercise)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '100.00']);
    ProjectDeferral::factory()->carryover('25.00')->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $sourceExercise->id,
        'destination_exercise_id' => $destinationExercise->id,
    ]);

    $sourceYear = supplierReport($viewer, $company, $sourceExercise);
    $destinationYear = supplierReport($viewer, $company, $destinationExercise);
    $sourceProject = collect($sourceYear->sources)->sole(fn ($source): bool => $source->originId === $project->id);
    $destinationProject = collect($destinationYear->sources)->sole(fn ($source): bool => $source->originId === $project->id);

    expect($sourceProject->allocation)->toBe('100.00')
        ->and($sourceProject->carryover)->toBe('25.00')
        ->and($sourceProject->receivedCarryover)->toBe('0.00')
        ->and(collect($sourceYear->sections[0]['rows'])->sum(fn (array $row): float => (float) $row['allocation']))->toBe(100.0)
        ->and($destinationProject->allocation)->toBe('25.00')
        ->and($destinationProject->carryover)->toBe('0.00')
        ->and($destinationProject->receivedCarryover)->toBe('25.00')
        ->and(collect($destinationYear->sections[0]['rows'])->sole()['allocation'])->toBe('25.00');
});

it('maps received and outgoing carryover from supported historical references', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $proposalOne = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $proposalTwo = Proposal::factory()->for($company)->for($exercise)->create(['purpose' => 'revision']);
    $budgetOne = BudgetSnapshot::factory()->for($proposalOne)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 1,
    ]);
    $budgetTwo = BudgetSnapshot::factory()->for($proposalTwo)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 2,
        'purpose' => 'revision',
        'previous_budget_id' => $budgetOne->id,
    ]);
    foreach ([$budgetOne, $budgetTwo] as $budget) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'source_type' => 'project',
            'origin_id' => 123,
            'origin_key' => 'project:historical',
            'approved_estimates' => '0.00',
            'approved_carryover' => '25.00',
            'approved_allocation' => '25.00',
            'detail' => ['expenses' => []],
        ]);
    }

    $budgetResult = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'budget_versions',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budgetOne->id],
        'final_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budgetTwo->id],
    ]));
    $budgetSource = $budgetResult->sources[0];

    $closing = closeExerciseFixture($exercise, $viewer);
    ClosingSourceRow::query()->create([
        'company_id' => $company->id,
        'closing_snapshot_id' => $closing->id,
        'source_type' => 'project',
        'origin_id' => 123,
        'origin_key' => 'project:historical',
        'label' => 'Progetto storico',
        'cost_center_label' => 'Non classificato',
        'end_state' => 'open',
        'has_actuals' => false,
        'final_estimates' => '0.00',
        'received_carryover' => '25.00',
        'final_allocation' => '25.00',
        'closing_actual' => '0.00',
        'operational_variance' => '-25.00',
        'detail_version' => 1,
        'detail' => ['expenses' => [], 'consolidated_carryover' => '10.00'],
    ]);
    $closingResult = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => 'closing',
        'final_reference' => ['type' => 'closing', 'exercise_id' => $exercise->id],
    ]));
    $closingSource = $closingResult->sources[0];

    expect($budgetSource->receivedCarryover)->toBe('25.00')
        ->and($budgetSource->carryover)->toBe('0.00')
        ->and(collect(app(ReportAggregator::class)->suppliers([$budgetSource]))->sole()['allocation'])->toBe('25.00')
        ->and($closingSource->receivedCarryover)->toBe('25.00')
        ->and($closingSource->carryover)->toBe('10.00')
        ->and(collect(app(ReportAggregator::class)->suppliers([$closingSource]))->sole()['allocation'])->toBe('25.00');
});

function supplierReport($viewer, Company $company, Exercise $exercise)
{
    return app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'suppliers',
    ]));
}
