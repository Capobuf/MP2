<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds every non comparison specialist family from one canonical source set', function (string $kind): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);

    $definition = ['company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => $kind];
    if ($kind === 'annual_executive') {
        $definition['actual_reference'] = 'current';
        $definition['final_reference'] = ['type' => 'current', 'exercise_id' => $exercise->id];
    }

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray($definition));

    expect($result->header['kind'])->toBe($kind)
        ->and($result->totals['actual'])->toBe('10.00');
})->with(['annual_executive', 'operational_variance', 'carryovers', 'contracts', 'projects', 'suppliers']);

it('builds every canonical comparison family with its explicit references', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $firstExercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $secondExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $firstExpense = Expense::factory()->forExercise($firstExercise)->create();
    $secondExpense = Expense::factory()->forExercise($secondExercise)->create();
    ExpenseLine::factory()->for($firstExpense)->actual()->create(['amount' => '10.00']);
    ExpenseLine::factory()->for($secondExpense)->actual()->create(['amount' => '15.00']);

    $proposal1 = Proposal::factory()->for($company)->for($firstExercise)->create(['status' => 'approved']);
    $proposal2 = Proposal::factory()->for($company)->for($firstExercise)->create(['purpose' => 'revision']);
    $budget1 = BudgetSnapshot::factory()->for($proposal1)->create([
        'company_id' => $company->id, 'exercise_id' => $firstExercise->id, 'version' => 1,
    ]);
    $budget2 = BudgetSnapshot::factory()->for($proposal2)->create([
        'company_id' => $company->id, 'exercise_id' => $firstExercise->id, 'version' => 2,
        'purpose' => 'revision', 'previous_budget_id' => $budget1->id,
    ]);
    foreach ([$budget1, $budget2] as $budget) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'origin_id' => $firstExpense->id,
            'origin_key' => $firstExpense->originKey(),
            'approved_allocation' => $budget->version === 1 ? '8.00' : '12.00',
        ]);
    }

    $definitions = [
        'budget_actual' => [
            'actual_reference' => 'current',
            'initial_reference' => ['type' => 'budget', 'exercise_id' => $firstExercise->id, 'budget_snapshot_id' => $budget1->id],
            'final_reference' => ['type' => 'current', 'exercise_id' => $firstExercise->id],
        ],
        'budget_current_allocation' => [
            'initial_reference' => ['type' => 'budget', 'exercise_id' => $firstExercise->id, 'budget_snapshot_id' => $budget1->id],
            'final_reference' => ['type' => 'current', 'exercise_id' => $firstExercise->id],
        ],
        'budget_versions' => [
            'initial_reference' => ['type' => 'budget', 'exercise_id' => $firstExercise->id, 'budget_snapshot_id' => $budget1->id],
            'final_reference' => ['type' => 'budget', 'exercise_id' => $firstExercise->id, 'budget_snapshot_id' => $budget2->id],
        ],
        'exercises' => [
            'comparison_exercise_id' => $secondExercise->id,
            'initial_reference' => ['type' => 'current', 'exercise_id' => $firstExercise->id],
            'final_reference' => ['type' => 'current', 'exercise_id' => $secondExercise->id],
        ],
    ];

    foreach ($definitions as $kind => $references) {
        $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
            'company_id' => $company->id,
            'exercise_id' => $firstExercise->id,
            'kind' => $kind,
            ...$references,
        ]));

        expect($result->header['kind'])->toBe($kind)
            ->and($result->comparisons)->not->toBeEmpty();
    }
});

it('labels contract expiry only for an explicitly selected interval', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    Contract::factory()->for($company)->create(['next_expiry_date' => '2026-06-15']);

    $withInterval = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
    ]));
    $withoutInterval = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'contracts',
    ]));

    expect($withInterval->sections[0]['rows'][0]['labels'])
        ->toContain('Scadenza contrattuale entro l’intervallo selezionato')
        ->and($withoutInterval->sections[0]['rows'][0]['labels'])->toBe([]);
});
