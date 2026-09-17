<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportResult;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00 Europe/Rome'));
});

function loadingReport(User $viewer, Exercise $exercise, array $filters = [], string $kind = 'operational_variance'): ReportResult
{
    return app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $exercise->company_id, 'exercise_id' => $exercise->id,
        'kind' => $kind, 'filters' => $filters,
    ]));
}

function recordReportLoads(Closure $callback): array
{
    $loaded = [];
    Event::listen('eloquent.retrieved: *', function (string $event, array $models) use (&$loaded): void {
        $model = $models[0];
        $loaded[$model::class][] = $model->getKey();
    });
    try {
        $result = $callback();
    } finally {
        Event::forget('eloquent.retrieved: *');
    }

    return [$result, $loaded];
}

it('narrows current candidates before loading detail and preserves whole-source amounts', function (string $filter) {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $viewer = s11ReportingViewer($company);
    $supplier = Supplier::factory()->for($company)->create();
    $otherSupplier = Supplier::factory()->for($company)->create();
    $parent = CostCenter::factory()->for($company)->create();
    $leaf = CostCenter::factory()->for($company)->create(['parent_id' => $parent->id]);
    $otherCenter = CostCenter::factory()->for($company)->create();
    $selected = [];
    $excludedExpenseIds = [];
    $selectedExpenseIds = [];
    foreach ([true, false] as $matching) {
        $centerId = $matching && $filter === 'unclassified' ? null : ($matching ? $leaf->id : $otherCenter->id);
        $supplierId = $matching ? $supplier->id : $otherSupplier->id;
        $project = Project::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['supplier_id' => $supplierId]);
        ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $centerId]);
        ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create(['cost_center_id' => $centerId]);
        foreach (['expense', 'project', 'contract'] as $type) {
            $expense = Expense::factory()->forExercise($exercise)->create([
                'project_id' => $type === 'project' ? $project->id : null,
                'contract_id' => $type === 'contract' ? $contract->id : null,
                'direct_cost_center_id' => $type === 'expense' ? $centerId : null,
                'supplier_id' => $supplierId,
            ]);
            ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
            if ($matching) {
                $selected[$type] = $type === 'expense' ? $expense : ($type === 'project' ? $project : $contract);
                $selectedExpenseIds[$type] = $expense->id;
            } else {
                $excludedExpenseIds[] = $expense->id;
            }
        }
        if ($matching) {
            $additional = Expense::factory()->forExercise($exercise)->for($project)->create(['supplier_id' => $otherSupplier->id]);
            ExpenseLine::factory()->for($additional)->actual()->create(['amount' => '20.00']);
        }
    }
    $unfiltered = loadingReport($viewer, $exercise);
    [$filters, $types] = match ($filter) {
        'project' => [['project_id' => $selected['project']->id], ['project']],
        'contract' => [['contract_id' => $selected['contract']->id], ['contract']],
        'expense' => [['expense_id' => $selected['expense']->id], ['expense']],
        'project expense' => [['expense_id' => $selectedExpenseIds['project']], ['project']],
        'contract expense' => [['expense_id' => $selectedExpenseIds['contract']], ['contract']],
        'supplier' => [['supplier_id' => $supplier->id], ['expense', 'project', 'contract']],
        'cost center' => [['cost_center_id' => $parent->id], ['expense', 'project', 'contract']],
        'unclassified' => [['cost_center_id' => 'unclassified'], ['expense', 'project', 'contract']],
    };
    $keys = collect($types)->map(fn (string $type) => $selected[$type]->originKey());
    $expected = collect($unfiltered->sources)->filter(fn ($source) => $keys->contains($source->originKey))->values()->all();

    [$filtered, $loaded] = recordReportLoads(fn () => loadingReport($viewer, $exercise, $filters));

    expect($filtered->sources)->toEqual($expected)
        ->and(array_intersect($loaded[Expense::class] ?? [], $excludedExpenseIds))->toBeEmpty();
    if (in_array('project', $types, true)) {
        expect(collect($filtered->sources)->firstWhere('sourceType', 'project')->actual)->toBe('30.00');
    }
})->with(['project', 'contract', 'expense', 'project expense', 'contract expense', 'supplier', 'cost center', 'unclassified']);

it('does not load heavy historical graphs for annually irrelevant sources', function () {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $previous = Exercise::factory()->for($company)->create(['year' => 2020]);
    $currentExpense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($currentExpense)->actual()->create(['amount' => '35.00']);
    $baseline = loadingReport($viewer, $exercise);
    for ($index = 0; $index < 12; $index++) {
        $project = Project::factory()->for($company)->create(['initial_state' => 'closed', 'initial_effective_date' => '2020-01-01']);
        $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2020-01-01']);
        ContractLifecycleFact::factory()->forContract($contract)->create([
            'type' => 'cessation', 'declared_contractual_date' => '2020-12-30',
            'state_change_date' => '2020-12-31', 'reason' => 'Contratto concluso',
        ]);
        ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2020-01-01', 'valid_to' => '2020-12-30']);
        foreach ([$previous, $exercise] as $year) {
            ProjectExerciseClassification::factory()->forProjectAndExercise($project, $year)->create();
            ContractExerciseClassification::factory()->forContractAndExercise($contract, $year)->create();
            foreach (['project', 'contract'] as $type) {
                $expense = Expense::factory()->forExercise($year)->create([$type.'_id' => ${$type}->id]);
                ExpenseLine::factory()->for($expense)->create(['amount' => $year->is($previous) ? '100.00' : '0.00', 'note' => 'Stima']);
            }
        }
    }

    [$result, $loaded] = recordReportLoads(fn () => loadingReport($viewer, $exercise));

    expect($result->sources)->toEqual($baseline->sources)
        ->and($result->totals)->toEqual($baseline->totals)
        ->and($result->costCenters)->toEqual($baseline->costCenters)
        ->and($loaded[Expense::class] ?? [])->toBe([$currentExpense->id])
        ->and($loaded[ExpenseLine::class] ?? [])->toHaveCount(1)
        ->and($loaded[ContractCondition::class] ?? [])->toBeEmpty()
        ->and($loaded[ProjectExerciseClassification::class] ?? [])->toBeEmpty()
        ->and($loaded[ContractExerciseClassification::class] ?? [])->toBeEmpty();
});

it('materializes only the requested specialist family', function (string $kind, string $type) {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $expenseIds = [];
    foreach (['expense', 'project', 'contract'] as $family) {
        $expense = Expense::factory()->forExercise($exercise)->create([
            'project_id' => $family === 'project' ? $project->id : null,
            'contract_id' => $family === 'contract' ? $contract->id : null,
        ]);
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
        $expenseIds[$family] = $expense->id;
    }
    $all = loadingReport($viewer, $exercise);
    $expected = collect($all->sources)->where('sourceType', $type)->values()->all();

    [$result, $loaded] = recordReportLoads(fn () => loadingReport($viewer, $exercise, kind: $kind));

    expect($result->sources)->toEqual($expected)
        ->and($result->totals['actual'])->toBe('10.00')
        ->and($result->totals['current_actual'])->toBe('30.00')
        ->and($loaded[$type === 'project' ? Contract::class : Project::class] ?? [])->toBeEmpty()
        ->and($loaded[Expense::class] ?? [])->toBe([$expenseIds[$type]]);
})->with([['contracts', 'contract'], ['projects', 'project'], ['carryovers', 'project']]);
