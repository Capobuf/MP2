<?php

use App\Filament\Widgets\AllocationComparisonScatterChart;
use App\Filament\Widgets\BudgetVariationChart;
use App\Filament\Widgets\CostCenterEconomicChart;
use App\Filament\Widgets\EconomicSummary;
use App\Filament\Widgets\OperationalVarianceBySourceChart;
use App\Filament\Widgets\SourceEconomicProfileChart;
use App\Livewire\ExerciseContextSelector;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Support\BudgetContext;
use App\Support\ExerciseContext;
use App\Support\Reporting\EconomicDashboardReadModel;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function economicDashboardFixture(): array
{
    $company = Company::factory()->create(['name' => 'Dashboard Company', 'timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Operations']);

    $standalone = Expense::factory()->forExercise($exercise)->create([
        'description' => 'Licenze autonome',
        'direct_cost_center_id' => $costCenter->id,
    ]);
    ExpenseLine::factory()->for($standalone)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($standalone)->actual()->create(['amount' => '110.00']);

    $project = Project::factory()->for($company)->create([
        'title' => 'Progetto Atlas',
        'initial_state' => 'open',
        'initial_effective_date' => '2026-01-01',
    ]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $projectExpense = Expense::factory()->forExercise($exercise)->for($project)->create(['description' => 'Spesa figlia progetto']);
    ExpenseLine::factory()->for($projectExpense)->create(['amount' => '200.00']);
    ExpenseLine::factory()->for($projectExpense)->actual()->create(['amount' => '180.00']);

    $contract = Contract::factory()->for($company)->create([
        'title' => 'Contratto Cloud',
        'contractual_start_date' => '2026-01-01',
    ]);
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create([
        'cost_center_id' => $costCenter->id,
    ]);
    $contractExpense = Expense::factory()->forExercise($exercise)->for($contract)->create(['description' => 'Spesa figlia contratto']);
    ExpenseLine::factory()->for($contractExpense)->create(['amount' => '300.00']);
    ExpenseLine::factory()->for($contractExpense)->actual()->create(['amount' => '350.00']);

    $proposal1 = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $proposal2 = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved', 'purpose' => 'revision']);
    $budget1 = BudgetSnapshot::factory()->for($proposal1)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 1,
        'total_approved_allocation' => '580.00',
    ]);
    $budget2 = BudgetSnapshot::factory()->for($proposal2)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 2,
        'purpose' => 'revision',
        'previous_budget_id' => $budget1->id,
        'total_approved_allocation' => '650.00',
    ]);

    foreach ([
        [$standalone, 'expense', 'Licenze autonome', $costCenter->id, 'Operations', '90.00', '100.00', 'active'],
        [$project, 'project', 'Progetto Atlas', null, 'Non classificato', '210.00', '220.00', 'open'],
        [$contract, 'contract', 'Contratto Cloud', $costCenter->id, 'Operations', '280.00', '330.00', 'active'],
    ] as [$source, $type, $label, $costCenterId, $costCenterLabel, $v1, $v2, $state]) {
        foreach ([[$budget1, $v1], [$budget2, $v2]] as [$budget, $amount]) {
            BudgetSourceRow::factory()->for($budget, 'budget')->create([
                'company_id' => $company->id,
                'source_type' => $type,
                'origin_id' => $source->id,
                'origin_key' => $type.':'.$source->id,
                'label' => $label,
                'cost_center_id' => $costCenterId,
                'cost_center_label' => $costCenterLabel,
                'approved_allocation' => $amount,
                'end_state' => $state,
            ]);
        }
    }

    return compact('company', 'viewer', 'exercise', 'costCenter', 'standalone', 'project', 'contract', 'budget1', 'budget2');
}

/** @return array<string, mixed> */
function chartData(string $widget): array
{
    $instance = Livewire::test($widget)->assertSuccessful()->instance();
    $method = new ReflectionMethod($instance, 'getData');

    return $method->invoke($instance);
}

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('uses the selected Budget and counts every primary source exactly once', function (): void {
    $fixture = economicDashboardFixture();
    $this->actingAs($fixture['viewer']);
    Filament::setTenant($fixture['company']);
    app(ExerciseContext::class)->select($fixture['company'], $fixture['exercise']->id);
    app(BudgetContext::class)->select($fixture['company'], $fixture['exercise'], $fixture['budget1']->id);

    $dashboard = app(EconomicDashboardReadModel::class)->load(
        $fixture['viewer'],
        $fixture['company'],
        $fixture['exercise'],
        $fixture['budget1'],
    );

    expect($dashboard['summary'])->toBe([
        'budget' => '580.00',
        'allocation' => '600.00',
        'actual' => '640.00',
        'operational_variance' => '40.00',
    ])->and($dashboard['sources'])->toHaveCount(3)
        ->and(collect($dashboard['sources'])->countBy('source_type')->all())->toBe([
            'contract' => 1,
            'project' => 1,
            'expense' => 1,
        ])
        ->and(collect($dashboard['sources'])->pluck('origin_key'))->not->toContain(
            $fixture['project']->expenses->first()?->originKey(),
            $fixture['contract']->expenses->first()?->originKey(),
        );

    $unclassified = collect($dashboard['cost_centers'])->firstWhere('key', 'unclassified');
    expect($unclassified)->not->toBeNull()
        ->and($unclassified['label'])->toBe('Non classificato')
        ->and($unclassified['budget'])->toBe('210.00')
        ->and($unclassified['allocation'])->toBe('200.00')
        ->and($unclassified['actual'])->toBe('180.00');
});

it('changes the exact comparison version without a silent Budget fallback', function (): void {
    $fixture = economicDashboardFixture();
    $this->actingAs($fixture['viewer']);
    Filament::setTenant($fixture['company']);
    $context = app(BudgetContext::class);

    expect($context->current($fixture['company'], $fixture['exercise']))->toBeNull();

    $context->select($fixture['company'], $fixture['exercise'], $fixture['budget1']->id);
    $v1 = app(EconomicDashboardReadModel::class)->load($fixture['viewer'], $fixture['company'], $fixture['exercise'], $fixture['budget1']);
    $context->select($fixture['company'], $fixture['exercise'], $fixture['budget2']->id);
    $v2 = app(EconomicDashboardReadModel::class)->load($fixture['viewer'], $fixture['company'], $fixture['exercise'], $fixture['budget2']);

    expect($v1['budget_id'])->toBe($fixture['budget1']->id)
        ->and($v1['summary']['budget'])->toBe('580.00')
        ->and($v2['budget_id'])->toBe($fixture['budget2']->id)
        ->and($v2['summary']['budget'])->toBe('650.00')
        ->and(collect($v1['sources'])->firstWhere('origin_key', $fixture['standalone']->originKey())['budget'])->toBe('90.00')
        ->and(collect($v2['sources'])->firstWhere('origin_key', $fixture['standalone']->originKey())['budget'])->toBe('100.00');
});

it('uses ComparisonEngine primary categories and counts the union once', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $unchanged = Expense::factory()->forExercise($exercise)->create(['description' => 'Invariata']);
    ExpenseLine::factory()->for($unchanged)->create(['amount' => '100.00']);
    $modified = Expense::factory()->forExercise($exercise)->create(['description' => 'Modificata']);
    ExpenseLine::factory()->for($modified)->create(['amount' => '80.00']);
    $added = Expense::factory()->forExercise($exercise)->create(['description' => 'Aggiunta']);
    ExpenseLine::factory()->for($added)->create(['amount' => '50.00']);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'total_approved_allocation' => '260.00',
    ]);
    foreach ([
        [$unchanged->id, $unchanged->originKey(), 'Invariata', '100.00'],
        [$modified->id, $modified->originKey(), 'Modificata', '100.00'],
        [999999, 'expense:999999', 'Rimossa', '60.00'],
    ] as [$id, $key, $label, $amount]) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'origin_id' => $id,
            'origin_key' => $key,
            'label' => $label,
            'cost_center_label' => 'Non classificato',
            'approved_allocation' => $amount,
            'end_state' => 'active',
        ]);
    }
    $this->actingAs($viewer);
    Filament::setTenant($company);

    $dashboard = app(EconomicDashboardReadModel::class)->load($viewer, $company, $exercise, $budget);

    expect($dashboard['comparison_categories'])->toBe([
        'unchanged' => 1,
        'added' => 1,
        'removed' => 1,
        'modified' => 1,
    ])->and($dashboard['comparison_source_count'])->toBe(4)
        ->and($dashboard['sources'])->toHaveCount(4);
});

it('provides correct scatter coordinates and positive negative and zero operational variances', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id]);
    foreach ([['Positivo', '100.00', '120.00', '90.00'], ['Negativo', '100.00', '80.00', '110.00'], ['Zero', '100.00', '100.00', '100.00']] as [$label, $allocation, $actual, $approved]) {
        $expense = Expense::factory()->forExercise($exercise)->create(['description' => $label]);
        ExpenseLine::factory()->for($expense)->create(['amount' => $allocation]);
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => $actual]);
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'origin_id' => $expense->id,
            'origin_key' => $expense->originKey(),
            'label' => $label,
            'approved_allocation' => $approved,
            'end_state' => 'active',
        ]);
    }
    $this->actingAs($viewer);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);
    app(BudgetContext::class)->select($company, $exercise, $budget->id);

    $scatter = chartData(AllocationComparisonScatterChart::class);
    $points = collect($scatter['datasets'][0]['data'])->keyBy('label');
    expect($points['Positivo'])->toMatchArray(['x' => 90.0, 'y' => 100.0, 'variation' => '10.00'])
        ->and($points['Negativo'])->toMatchArray(['x' => 110.0, 'y' => 100.0, 'variation' => '-10.00']);

    $variance = chartData(OperationalVarianceBySourceChart::class);
    $values = collect($variance['labels'])->mapWithKeys(fn (string $label, int $index): array => [
        $label => $variance['datasets'][0]['data'][$index],
    ]);
    expect($values->all())->toMatchArray(['Positivo' => 20.0, 'Negativo' => -20.0, 'Zero' => 0.0])
        ->and($variance['datasets'][0]['label'])->toBe('Scostamento Operativo');
});

it('rejects foreign Budget context and foreign Dashboard data', function (): void {
    $fixture = economicDashboardFixture();
    $otherCompany = Company::factory()->create();
    $otherExercise = Exercise::factory()->for($otherCompany)->create();
    $otherProposal = Proposal::factory()->for($otherCompany)->for($otherExercise)->create();
    $foreignBudget = BudgetSnapshot::factory()->for($otherProposal)->create();

    expect(fn () => app(BudgetContext::class)->select($fixture['company'], $fixture['exercise'], $foreignBudget->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(EconomicDashboardReadModel::class)->load(
            $fixture['viewer'], $fixture['company'], $fixture['exercise'], $foreignBudget,
        ))->toThrow(ValidationException::class);
});

it('renders every chart and handles no Exercise no Budget and no sources', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $this->actingAs($viewer);
    Filament::setTenant($company);

    Livewire::test(EconomicSummary::class)
        ->assertSuccessful()
        ->assertSee('Nessun Esercizio selezionato');

    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    app(ExerciseContext::class)->select($company, $exercise->id);
    Livewire::test(SourceEconomicProfileChart::class)
        ->assertSuccessful()
        ->assertSee('Seleziona una versione di Budget');

    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id]);
    app(BudgetContext::class)->select($company, $exercise, $budget->id);

    foreach ([SourceEconomicProfileChart::class, CostCenterEconomicChart::class, BudgetVariationChart::class, AllocationComparisonScatterChart::class, OperationalVarianceBySourceChart::class] as $widget) {
        Livewire::test($widget)
            ->assertSuccessful()
            ->assertDontSee('Risparmio');
    }

    Livewire::test(ExerciseContextSelector::class)
        ->assertSet('exerciseId', $exercise->id)
        ->assertSet('budgetId', $budget->id)
        ->assertSee('Budget v1')
        ->assertSeeHtml('aria-label="Budget globale"');
});

it('preserves every Cost Center when the Radar degrades for high cardinality', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id]);

    foreach (range(1, 13) as $index) {
        $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Centro '.$index]);
        $expense = Expense::factory()->forExercise($exercise)->create([
            'description' => 'Sorgente '.$index,
            'direct_cost_center_id' => $costCenter->id,
        ]);
        ExpenseLine::factory()->for($expense)->create(['amount' => (string) ($index * 10).'.00']);
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'company_id' => $company->id,
            'origin_id' => $expense->id,
            'origin_key' => $expense->originKey(),
            'label' => $expense->description,
            'cost_center_id' => $costCenter->id,
            'cost_center_label' => $costCenter->name,
            'approved_allocation' => (string) ($index * 10).'.00',
            'end_state' => 'active',
        ]);
    }
    $this->actingAs($viewer);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);
    app(BudgetContext::class)->select($company, $exercise, $budget->id);

    $component = Livewire::test(CostCenterEconomicChart::class)->assertSuccessful()->instance();
    $type = new ReflectionMethod($component, 'getType');
    $data = new ReflectionMethod($component, 'getData');

    expect($type->invoke($component))->toBe('bar')
        ->and($data->invoke($component)['labels'])->toHaveCount(13);
});

it('keeps the Dashboard report query count stable as primary containers grow', function (): void {
    $createCompany = function (int $projectCount): array {
        $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
        $viewer = s11ReportingViewer($company);
        $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
        foreach (range(1, $projectCount) as $index) {
            $project = Project::factory()->for($company)->create([
                'title' => 'Progetto '.$index,
                'initial_state' => 'open',
                'initial_effective_date' => '2026-01-01',
            ]);
            $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
            ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
        }

        return compact('company', 'viewer', 'exercise');
    };

    $small = $createCompany(1);
    $large = $createCompany(8);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($small['viewer']);
    Filament::setTenant($small['company']);
    app(EconomicDashboardReadModel::class)->load($small['viewer'], $small['company'], $small['exercise'], null);
    $smallCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->actingAs($large['viewer']);
    Filament::setTenant($large['company']);
    app(EconomicDashboardReadModel::class)->load($large['viewer'], $large['company'], $large['exercise'], null);
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeCount)->toBeLessThanOrEqual($smallCount + 1);
});
