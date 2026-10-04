<?php

use App\Actions\Operations\CreateExpense;
use App\Actions\Operations\UpdateExpense;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Reporting\BuildReport;
use App\Domain\Expenses\Decimal;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Reporting\ReportAggregator;
use App\Domain\Reporting\ReportDefinition;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('uses the domain residual for an overspent current Project', function (): void {
    CarbonImmutable::setTestNow('2026-09-07 10:00:00 Europe/Rome');
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $viewer = s11ReportingViewer($company);
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $viewer,
        'permissions' => TestPermissions::MANAGE_OPERATIONS,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2026-01-01',
    ]);

    app(CreateExpense::class)->execute($viewer, $company, [
        'exercise_id' => $exercise->id,
        'project_id' => $project->id,
        'description' => 'Spesa in sovraspesa',
        'lines' => [
            ['type' => 'estimate', 'amount' => '100.00'],
            ['type' => 'actual', 'amount' => '150.00'],
        ],
    ], (string) Str::uuid());

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'projects',
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ], CarbonImmutable::now()));

    $source = collect($result->sources)->sole(fn ($source): bool => $source->originId === $project->id);
    expect($source->allocation)->toBe('100.00')
        ->and($source->actual)->toBe('150.00')
        ->and($source->residual)->toBe('0.00')
        ->and($source->detail['residual'])->toBe('0.00')
        ->and($result->totals['current_operational_variance'])->toBe('50.00');

    $underBudgetProject = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2026-01-01',
    ]);
    app(CreateExpense::class)->execute($viewer, $company, [
        'exercise_id' => $exercise->id,
        'project_id' => $underBudgetProject->id,
        'description' => 'Spesa entro l’allocato',
        'lines' => [
            ['type' => 'estimate', 'amount' => '100.00'],
            ['type' => 'actual', 'amount' => '40.00'],
        ],
    ], (string) Str::uuid());

    $updatedResult = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'kind' => 'projects',
        'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ], CarbonImmutable::now()));
    $underBudgetSource = collect($updatedResult->sources)
        ->sole(fn ($reportSource): bool => $reportSource->originId === $underBudgetProject->id);

    expect($underBudgetSource->residual)->toBe('60.00')
        ->and($underBudgetSource->detail['residual'])->toBe('60.00');
});

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

it('IR07 counts a planned child only through its historical parent across Budget reports and versions', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s11ReportingViewer($company);
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::all()]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $root = CostCenter::factory()->for($company)->create();
    $leaf = CostCenter::factory()->for($company)->create(['parent_id' => $root->id]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $leaf->id]);
    $supplier = Supplier::factory()->for($company)->create();
    $otherSupplier = Supplier::factory()->for($company)->create();
    $standalone = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id, 'description' => 'Autonoma distinta', 'supplier_id' => $otherSupplier->id,
        'lines' => [['type' => 'estimate', 'amount' => '50.00']],
    ], (string) Str::uuid());
    app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id, 'project_id' => $project->id, 'description' => 'Seconda componente del progetto',
        'supplier_id' => $otherSupplier->id, 'lines' => [['type' => 'estimate', 'amount' => '100.00']],
    ], (string) Str::uuid());
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $action = app(PlanExpense::class)->create($actor, $proposal, [
        'exercise_id' => $exercise->id, 'description' => 'Nuova allocazione del progetto',
        'project_id' => $project->id, 'project_item_id' => null, 'supplier_id' => $supplier->id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '300.00', 'note' => null, 'annulled' => false]],
    ], 'Allocazione approvata', (string) Str::uuid(), $proposal->refresh()->revision, ProposalActionType::CreateProjectAllocation);
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $childRow = $budget->rows()->where('proposal_item_id', $action->item->proposal_item_id)->sole();
    $storedRows = $budget->rows()->orderBy('id')->get()->toArray();
    expect($budget->total_approved_allocation)->toBe('450.00')
        ->and($storedRows)->toHaveCount(3)
        ->and($childRow->approved_allocation)->toBe('300.00')
        ->and(data_get($childRow->detail, 'expense.owner.type'))->toBe('project');
    $reference = ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget->id];
    $definition = ['company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers', 'final_reference' => $reference];
    $report = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray($definition));
    expect(collect($report->sources)->pluck('originKey')->sort()->values()->all())->toBe(collect([$project->originKey(), $standalone->originKey()])->sort()->values()->all())
        ->and($report->totals['allocation'])->toBe('450.00')
        ->and($report->totals['source_count'])->toBe(2);
    $aggregator = app(ReportAggregator::class);
    $suppliers = collect($aggregator->suppliers($report->sources))->keyBy('key');
    expect($suppliers['supplier:'.$supplier->id]['allocation'])->toBe('300.00')
        ->and($suppliers['supplier:'.$otherSupplier->id]['allocation'])->toBe('150.00');
    $centers = collect($report->costCenters)->keyBy('key');
    expect(Decimal::sum($centers->pluck('direct_allocation')))->toBe('450.00')
        ->and($centers['cost-center:'.$root->id]['branch_allocation'])->toBe('400.00')
        ->and($centers['unclassified']['direct_allocation'])->toBe('50.00');
    foreach ([['supplier_id' => $supplier->id], ['expense_id' => $childRow->origin_id], ['project_id' => $project->id]] as $filter) {
        $filtered = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray([...$definition, 'filters' => $filter]));
        expect($filtered->sources)->toHaveCount(1)
            ->and($filtered->sources[0]->originKey)->toBe($project->originKey())
            ->and($filtered->sources[0]->allocation)->toBe('400.00');
    }
    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $nextBudget = app(ApproveProposal::class)->execute($actor, $revision, (string) Str::uuid(), ['reason' => 'Conferma piano']);
    foreach ([
        ['kind' => 'budget_current_allocation', 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id]],
        ['kind' => 'budget_versions', 'final_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $nextBudget->id]],
    ] as $comparison) {
        $result = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray([...$definition, ...$comparison, 'initial_reference' => $reference]));
        expect($result->comparisons)->toHaveCount(2)
            ->and(array_column($result->comparisons, 'delta'))->toBe(['0.00', '0.00'])
            ->and(array_column($result->comparisons, 'origin_key'))->not->toContain($childRow->origin_key);
    }
    expect($budget->rows()->orderBy('id')->get()->toArray())->toBe($storedRows);

    $child = Expense::query()->findOrFail($childRow->origin_id);
    $move = app(UpdateExpense::class);
    $preview = $move->preview($actor, $child, ['project_id' => null, 'direct_cost_center_id' => null, 'reason' => 'Cambio contenitore dopo Budget']);
    $move->confirm($actor, $child, $preview, (string) Str::uuid());
    $preview = $move->preview($actor, $standalone->fresh(), ['project_id' => $project->id, 'reason' => 'Cambio contenitore dopo Budget']);
    $move->confirm($actor, $standalone->fresh(), $preview, (string) Str::uuid());
    expect($child->fresh()->project_id)->toBeNull()
        ->and($standalone->fresh()->project_id)->toBe($project->id);
    $current = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray([
        ...$definition, 'final_reference' => ['type' => 'current', 'exercise_id' => $exercise->id],
    ]));
    expect(collect($current->sources)->pluck('originKey')->sort()->values()->all())
        ->toBe(collect([$project->originKey(), $child->originKey()])->sort()->values()->all())
        ->and($current->totals['allocation'])->toBe('450.00');
    $historical = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray($definition));
    expect(collect($historical->sources)->mapWithKeys(fn ($source): array => [$source->originKey => $source->allocation])->all())
        ->toBe(collect($report->sources)->mapWithKeys(fn ($source): array => [$source->originKey => $source->allocation])->all())
        ->and($budget->rows()->orderBy('id')->get()->toArray())->toBe($storedRows);
});

it('IR07 uses supported historical ownership including zero independently of the live owner', function (int $version): void {
    $company = Company::factory()->create();
    $actor = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id]);
    foreach (['standalone', 'project', 'contract'] as $owner) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'detail_version' => $version, 'detail' => ['schema_version' => $version, 'expense' => ['owner' => ['type' => $owner]]],
        ]);
    }
    foreach (['project', 'contract'] as $type) {
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'source_type' => $type, 'origin_key' => $type.':'.Str::uuid(),
            'detail_version' => $version, 'detail' => ['schema_version' => $version, $type => []],
        ]);
    }
    $result = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers',
        'final_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget->id],
    ]));
    expect(collect($result->sources)->pluck('sourceType')->sort()->values()->all())->toBe(['contract', 'expense', 'project'])
        ->and(array_column($result->sources, 'allocation'))->toBe(['0.00', '0.00', '0.00'])
        ->and(data_get(collect($result->sources)->firstWhere('sourceType', 'expense')->detail, 'expense.owner.type'))->toBe('standalone')
        ->and($budget->rows()->count())->toBe(5);
})->with([1, 2, 3]);

it('IR07 rejects missing or unsupported historical ownership without changing the Budget', function (?string $owner): void {
    $company = Company::factory()->create();
    $actor = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id]);
    $row = BudgetSourceRow::factory()->for($budget, 'budget')->create(['detail' => ['expense' => ['owner' => ['type' => $owner]]]]);
    $stored = $row->refresh()->getAttributes();
    expect(fn () => app(BuildReport::class)->execute($actor, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers',
        'final_reference' => ['type' => 'budget', 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $budget->id],
    ])))->toThrow(ValidationException::class, 'appartenenza storica')
        ->and($row->refresh()->getAttributes())->toBe($stored);
})->with([null, 'unknown']);
