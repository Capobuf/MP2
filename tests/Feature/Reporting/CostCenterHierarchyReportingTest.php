<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ModificationDimension;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportResult;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hierarchyReport(Company $company, Exercise $exercise, User $viewer, int|string|null $costCenterId = null): ReportResult
{
    return app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => 'current',
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
        'filters' => $costCenterId === null ? [] : ['cost_center_id' => $costCenterId],
    ]));
}

it('applies one subtree filter semantics to leaf parent root and unclassified', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $it = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $software = CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $it->id]);
    $saas = CostCenter::factory()->for($company)->create(['name' => 'SaaS', 'parent_id' => $software->id]);

    $makeExpense = function (string $description, string $amount, ?CostCenter $costCenter) use ($exercise): Expense {
        $expense = Expense::factory()->forExercise($exercise)->create([
            'description' => $description,
            'direct_cost_center_id' => $costCenter?->id,
        ]);
        ExpenseLine::factory()->for($expense)->create(['amount' => $amount]);

        return $expense;
    };
    $direct = $makeExpense('IT diretto', '10.00', $it);
    $softwareExpense = $makeExpense('Software', '20.00', $software);
    $saasExpense = $makeExpense('SaaS', '30.00', $saas);
    $unclassified = $makeExpense('Senza CdC', '40.00', null);

    $all = hierarchyReport($company, $exercise, $viewer);
    $itBucket = collect($all->costCenters)->firstWhere('cost_center_id', $it->id);

    expect($all->totals['current_allocation'])->toBe('100.00')
        ->and($itBucket['direct_allocation'])->toBe('10.00')
        ->and($itBucket['branch_allocation'])->toBe('60.00')
        ->and(collect(hierarchyReport($company, $exercise, $viewer, $saas->id)->sources)->pluck('originId')->all())->toBe([$saasExpense->id])
        ->and(collect(hierarchyReport($company, $exercise, $viewer, $software->id)->sources)->pluck('originId')->all())->toEqualCanonicalizing([$softwareExpense->id, $saasExpense->id])
        ->and(collect(hierarchyReport($company, $exercise, $viewer, $it->id)->sources)->pluck('originId')->all())->toEqualCanonicalizing([$direct->id, $softwareExpense->id, $saasExpense->id])
        ->and(collect(hierarchyReport($company, $exercise, $viewer, 'unclassified')->sources)->pluck('originId')->all())->toBe([$unclassified->id]);
});

it('compares materialized budget placement with the live hierarchy without changing direct identity', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $it = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $digital = CostCenter::factory()->for($company)->create(['name' => 'Digital']);
    $software = CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $digital->id]);
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $software->id]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 1,
        'total_approved_allocation' => '10.00',
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'origin_id' => $expense->id,
        'origin_key' => $expense->originKey(),
        'cost_center_id' => $software->id,
        'cost_center_label' => 'IT / Software',
        'approved_allocation' => '10.00',
        'detail_version' => 2,
        'detail' => ['cost_center_lineage' => [
            ['cost_center_id' => $it->id, 'cost_center_label' => 'IT'],
            ['cost_center_id' => $software->id, 'cost_center_label' => 'Software'],
        ]],
    ]);

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'budget_actual',
        'actual_reference' => 'current',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget->id],
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));
    $comparison = $result->comparisons[0];

    expect($comparison['initial_source']->costCenterLabel)->toBe('IT / Software')
        ->and($comparison['final_source']->costCenterLabel)->toBe('Digital / Software')
        ->and($comparison['initial_source']->costCenterId)->toBe($software->id)
        ->and($comparison['final_source']->costCenterId)->toBe($software->id)
        ->and($comparison['dimensions'])->toContain(ModificationDimension::CostCenterPlacement)
        ->not->toContain(ModificationDimension::CostCenter);

    $legacyBudget = BudgetSnapshot::factory()->for(Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']))->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 2,
        'purpose' => 'revision',
        'previous_budget_id' => $budget->id,
    ]);
    BudgetSourceRow::factory()->for($legacyBudget, 'budget')->create([
        'company_id' => $company->id,
        'origin_id' => $expense->id,
        'origin_key' => $expense->originKey(),
        'cost_center_id' => $software->id,
        'cost_center_label' => 'Software',
        'detail_version' => 1,
        'detail' => [],
    ]);
    $legacyResult = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'budget_actual',
        'actual_reference' => 'current',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $legacyBudget->id],
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
        'filters' => ['cost_center_id' => $digital->id],
    ]));

    expect($legacyResult->comparisons[0]['initial_source'])->toBeNull()
        ->and($legacyResult->comparisons[0]['final_source']->originId)->toBe($expense->id);
});

it('compares each budget with its own materialized hierarchy', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $it = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $digital = CostCenter::factory()->for($company)->create(['name' => 'Digital']);
    $software = CostCenter::factory()->for($company)->create(['name' => 'Software', 'parent_id' => $digital->id]);
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $software->id]);
    $firstProposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $firstBudget = BudgetSnapshot::factory()->for($firstProposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 1,
    ]);
    $secondProposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $secondBudget = BudgetSnapshot::factory()->for($secondProposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 2,
        'purpose' => 'revision',
        'previous_budget_id' => $firstBudget->id,
    ]);

    foreach ([
        [$firstBudget, 'IT / Software', $it],
        [$secondBudget, 'Digital / Software', $digital],
    ] as [$budget, $path, $parent]) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'origin_id' => $expense->id,
            'origin_key' => $expense->originKey(),
            'cost_center_id' => $software->id,
            'cost_center_label' => $path,
            'approved_allocation' => '10.00',
            'detail_version' => 2,
            'detail' => ['cost_center_lineage' => [
                ['cost_center_id' => $parent->id, 'cost_center_label' => $parent->name],
                ['cost_center_id' => $software->id, 'cost_center_label' => 'Software'],
            ]],
        ]);
    }

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'budget_versions',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $firstBudget->id],
        'final_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $secondBudget->id],
    ]));
    $comparison = $result->comparisons[0];

    expect($comparison['initial_source']->costCenterLabel)->toBe('IT / Software')
        ->and($comparison['final_source']->costCenterLabel)->toBe('Digital / Software')
        ->and($comparison['initial_source']->costCenterId)->toBe($software->id)
        ->and($comparison['final_source']->costCenterId)->toBe($software->id)
        ->and($comparison['dimensions'])->toContain(ModificationDimension::CostCenterPlacement)
        ->not->toContain(ModificationDimension::CostCenter);
});
