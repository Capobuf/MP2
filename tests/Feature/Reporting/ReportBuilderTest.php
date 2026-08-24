<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('builds the annual executive header and distinct current measures', function (): void {
    CarbonImmutable::setTestNow('2026-08-24 10:00:00 Europe/Rome');
    $company = Company::factory()->create(['name' => 'Acme', 'timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Licenze']);
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '70.00']);

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => 'current',
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ], CarbonImmutable::now()));

    expect($result->header)->toMatchArray([
        'company_name' => 'Acme',
        'exercise_year' => 2026,
        'kind' => 'annual_executive',
        'currency' => 'EUR',
        'amount_basis' => 'Importi netti IVA',
    ])->and($result->totals)->toMatchArray([
        'current_allocation' => '100.00',
        'current_actual' => '70.00',
        'current_operational_variance' => '-30.00',
    ])->and($result->sources)->toHaveCount(1);
});

it('uses the explicitly selected budget version in budget actual', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '90.00']);
    $proposal1 = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $proposal2 = Proposal::factory()->for($company)->for($exercise)->create(['purpose' => 'revision']);
    $budget1 = BudgetSnapshot::factory()->for($proposal1)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'version' => 1, 'total_approved_allocation' => '100.00']);
    $budget2 = BudgetSnapshot::factory()->for($proposal2)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'version' => 2, 'purpose' => 'revision', 'previous_budget_id' => $budget1->id, 'total_approved_allocation' => '120.00']);
    foreach ([[$budget1, '100.00'], [$budget2, '120.00']] as [$budget, $amount]) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'origin_id' => $expense->id,
            'origin_key' => $expense->originKey(),
            'approved_allocation' => $amount,
        ]);
    }

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'budget_actual',
        'actual_reference' => 'current',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget1->id],
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));

    expect($result->header['budget_version'])->toBe(1)
        ->and($result->comparisons)->toHaveCount(1)
        ->and($result->comparisons[0]['initial_source']->allocation)->toBe('100.00')
        ->and($result->comparisons[0]['final_source']->actual)->toBe('90.00');
});

it('fails explicitly when a requested closing is absent', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'version' => 1]);

    expect(fn () => app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'budget_actual',
        'actual_reference' => 'closing',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget->id],
        'final_reference' => ['type' => 'closing', 'exercise_id' => $exercise->id],
    ])))->toThrow(ValidationException::class, 'non esiste');
});

it('compares a selected budget with closing and current knowledge explicitly', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'version' => 1,
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'origin_id' => $expense->id,
        'origin_key' => $expense->originKey(),
        'approved_allocation' => '80.00',
    ]);
    $closing = closeExerciseFixture($exercise, $viewer);
    ClosingSourceRow::query()->create([
        'company_id' => $company->id,
        'closing_snapshot_id' => $closing->id,
        'source_type' => 'expense',
        'origin_id' => $expense->id,
        'origin_key' => $expense->originKey(),
        'label' => 'Voce chiusa',
        'cost_center_label' => 'Storico',
        'end_state' => 'active',
        'has_actuals' => true,
        'final_estimates' => '80.00',
        'received_carryover' => '0.00',
        'final_allocation' => '80.00',
        'closing_actual' => '75.00',
        'operational_variance' => '-5.00',
        'detail_version' => 1,
        'detail' => [],
    ]);

    foreach (['closing', 'current_knowledge'] as $actualReference) {
        $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
            'company_id' => $company->id,
            'exercise_id' => $exercise->id,
            'kind' => 'budget_actual',
            'actual_reference' => $actualReference,
            'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget->id],
            'final_reference' => ['type' => $actualReference, 'exercise_id' => $exercise->id],
        ]));

        expect($result->header['actual_reference'])->not->toBeNull()
            ->and($result->comparisons[0]['initial_value'])->toBe('80.00')
            ->and($result->comparisons[0]['final_value'])->toBe('75.00');
    }
});
