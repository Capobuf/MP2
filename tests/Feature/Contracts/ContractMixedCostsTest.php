<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Actions\Operations\CancelContract;
use App\Actions\Operations\CeaseContract;
use App\Actions\Operations\CreateContract;
use App\Actions\Operations\CreateContractCondition;
use App\Actions\Operations\CreateExpense;
use App\Actions\Operations\ReactivateContract;
use App\Actions\Operations\RecalculateContractEstimates;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateContract;
use App\Actions\Operations\UpdateExpenseLine;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Reporting\BuildReport;
use App\Domain\Contracts\ContractImpactFingerprint;
use App\Domain\Expenses\Decimal;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Domain\Reporting\ReportAggregator;
use App\Domain\Reporting\ReportDefinition;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-04 12:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

function mixedContractFixture(string $start = '2025-01-01', bool $recurring = true): array
{
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::all()]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $next = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = app(CreateContract::class)->execute($actor, $company, [
        'title' => 'Accordo B+D', 'supplier_id' => $supplier->id, 'contractual_start_date' => $start,
        'automatic_renewal' => false, 'conditions' => $recurring ? [[
            'amount' => '100.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => $start,
        ]] : [],
    ], (string) Str::uuid());

    return compact('company', 'actor', 'exercise', 'next', 'supplier', 'contract');
}

function mixedManual(array $f, Exercise $exercise, array $lines): Expense
{
    return app(CreateExpense::class)->execute($f['actor'], $f['company'], [
        'exercise_id' => $exercise->id, 'contract_id' => $f['contract']->id,
        'description' => 'Costo manuale', 'lines' => $lines,
    ], (string) Str::uuid());
}

it('concatenates mixed costs budgets realignment closing and the existing next year without copying manual costs', function (): void {
    $f = mixedContractFixture();
    extract($f);
    $manual = mixedManual($f, $exercise, [
        ['type' => 'estimate', 'amount' => '300.00', 'quantity' => '2', 'unit_amount' => '100', 'unit_of_measure' => 'licenze', 'amount_warning_acknowledged' => true],
        ['type' => 'actual', 'amount' => '900.00'],
    ]);
    $futureManual = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '450.00']]);
    $actualBefore = $manual->lines()->where('type', 'actual')->sole()->getAttributes();
    $estimate = $manual->lines()->where('type', 'estimate')->sole();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $v1 = app(ApproveProposal::class)->execute($actor, $proposal, (string) Str::uuid());
    expect($v1->total_approved_allocation)->toBe('1500.00');
    $oldDetail = $v1->rows()->sole()->detail;
    expect($oldDetail['contract']['system_estimate_total'])->toBe('1200.00')
        ->and($oldDetail['contract']['manual_estimate_total'])->toBe('300.00');
    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise->refresh(), (string) Str::uuid());
    app(UpdateExpenseLine::class)->execute($actor, $estimate, [
        'type' => 'estimate', 'amount' => '400.00', 'quantity' => '2', 'unit_amount' => '100', 'unit_of_measure' => 'licenze',
        'amount_warning_acknowledged' => true, 'change_reason' => 'Nuova previsione',
    ], (string) Str::uuid());
    expect(app(ProposalReadiness::class)->assessProposal($revision->fresh())['ready'])->toBeFalse()
        ->and($v1->fresh()->total_approved_allocation)->toBe('1500.00');
    $item = $revision->items()->where('contract_id', $contract->id)->sole();
    app(RealignProposalItem::class)->execute($actor, $revision, $item, ProposalRealignmentChoice::Reload, null, [], (string) Str::uuid(), $revision->refresh()->revision);
    $v2 = app(ApproveProposal::class)->execute($actor, $revision->refresh(), (string) Str::uuid(), ['reason' => 'Aggiornamento']);
    expect($v2->total_approved_allocation)->toBe('1600.00')->and($v1->rows()->sole()->detail)->toBe($oldDetail);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise->refresh(), ['projects' => []]);
    expect($prepared['review']->totals['final_allocation'])->toBe('1600.00');
    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, [...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'], 'warnings_acknowledged' => true, 'confirmed' => true,
    ], (string) Str::uuid());
    expect($snapshot->total_final_allocation)->toBe('1600.00')->and($snapshot->total_closing_actual)->toBe('900.00')
        ->and($next->refresh()->allocation())->toBe('1650.00')
        ->and($futureManual->fresh()->allocation())->toBe('450.00')
        ->and($manual->lines()->where('type', 'actual')->sole()->getAttributes())->toBe($actualBefore)
        ->and($estimate->fresh()->unit_of_measure)->toBe('licenze')
        ->and($contract->fresh()->economic_use_recorded)->toBeTrue();
});

it('preserves the real first recurring date and requires a current confirmed preview', function (string $date, string $expected): void {
    $f = mixedContractFixture('2026-01-01', false);
    extract($f);
    $input = ['amount' => '100.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => $date, 'reason' => 'Accordo documentato'];
    $action = app(CreateContractCondition::class);
    $preview = $action->preview($actor, $contract, $input);
    expect($contract->conditions()->count())->toBe(0)->and($preview['exerciseImpacts'][$next->id]['allocation_after'])->toBe($expected);
    $condition = $action->execute($actor, $contract, $input, (string) Str::uuid(), ContractImpactFingerprint::make($preview));
    expect($condition->validFrom()->toDateString())->toBe($date)->and($next->refresh()->allocation())->toBe($expected);
})->with([['2026-07-01', '600.00'], ['2026-10-10', '300.00']]);

it('cancels only the explicitly selected manual lines across open years and is retry safe', function (string $choice, string $expected): void {
    $f = mixedContractFixture('2027-01-01', false);
    extract($f);
    $one = mixedManual($f, $exercise, [['type' => 'estimate', 'amount' => '100.00'], ['type' => 'estimate', 'amount' => '200.00']]);
    $two = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '400.00']]);
    $action = app(CancelContract::class);
    $contract->refresh();
    $preview = $action->preview($actor, $contract);
    $all = collect($preview['candidates'])->pluck('line_id')->all();
    $selected = match ($choice) {
        'all' => $all, 'subset' => [$all[0]], default => []
    };
    $id = (string) Str::uuid();
    $fact = $action->execute($actor, $contract, 'Accordo annullato', $contract->revision, $id, $selected, ContractImpactFingerprint::make($preview));
    $retry = $action->execute($actor, $contract, 'Accordo annullato', $contract->revision, $id, $selected, ContractImpactFingerprint::make($preview));
    expect($retry->id)->toBe($fact->id)->and($contract->fresh()->stateAtDate('2026-10-04')->value)->toBe('cancelled')
        ->and(Decimal::add($one->fresh()->allocation(), $two->fresh()->allocation()))->toBe($expected)
        ->and($one->lines()->count())->toBe(2)->and($two->lines()->count())->toBe(1);
})->with([['keep', '700.00'], ['all', '0.00'], ['subset', '600.00']]);

it('rejects cancellation after a new manual cost and rolls back a failure on the second child', function (): void {
    $f = mixedContractFixture('2027-01-01', false);
    extract($f);
    $one = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '100.00']]);
    $contract->refresh();
    $action = app(CancelContract::class);
    $preview = $action->preview($actor, $contract);
    $two = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '200.00']]);
    expect(fn () => $action->execute($actor, $contract, 'Annullamento', $contract->revision, (string) Str::uuid(), [], ContractImpactFingerprint::make($preview)))->toThrow(ValidationException::class);
    $contract->refresh();
    $preview = $action->preview($actor, $contract);
    $original = app(SetExpenseLineActive::class);
    $count = 0;
    $mock = Mockery::mock(SetExpenseLineActive::class);
    $mock->shouldReceive('execute')->andReturnUsing(function (...$args) use ($original, &$count) {
        if (++$count === 2) {
            throw new RuntimeException('second child');
        }

        return $original->execute(...$args);
    });
    app()->instance(SetExpenseLineActive::class, $mock);
    expect(fn () => $action->execute($actor, $contract, 'Annullamento', $contract->revision, (string) Str::uuid(), collect($preview['candidates'])->pluck('line_id')->all(), ContractImpactFingerprint::make($preview)))->toThrow(RuntimeException::class)
        ->and($one->fresh()->allocation())->toBe('100.00')->and($two->fresh()->allocation())->toBe('200.00')
        ->and($contract->fresh()->stateAtDate('2026-10-04')->value)->toBe('planned');
});

it('plans one owner per manual cost, preserves mixed rows and keeps out-year costs out of the main Budget', function (): void {
    $f = mixedContractFixture();
    extract($f);
    $manual = mixedManual($f, $exercise, [
        ['type' => 'estimate', 'amount' => '300.00', 'quantity' => '3', 'unit_amount' => '100', 'unit_of_measure' => 'pezzi'],
        ['type' => 'actual', 'amount' => '900.00'],
    ]);
    mixedManual($f, $next, [['type' => 'estimate', 'amount' => '450.00']]);
    $actual = $manual->lines()->where('type', 'actual')->sole()->getAttributes();
    $line = $manual->lines()->where('type', 'estimate')->sole();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanContract::class)->execute($actor, $proposal, $item, ProposalActionType::PlanContractChildExpenses, [
        'existing_expenses' => [['expense_id' => $manual->id, 'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => '400.00',
            'amount_warning_acknowledged' => true, 'annulled' => false,
        ]]]],
    ], null, (string) Str::uuid(), $proposal->refresh()->revision);
    $child = app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Costo 2026', 'exercise_id' => $next->id, 'contract_id' => $contract->id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'amount' => '200.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), $proposal->refresh()->revision);
    $impacts = collect(ProposalImpactPlan::build($proposal->fresh()))->keyBy('exercise_id');
    expect($impacts[$exercise->id]['allocation_after'])->toBe('1600.00')->and($impacts[$next->id]['allocation_after'])->toBe('1850.00');
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $childRow = $budget->rows()->where('proposal_item_id', $child->item->proposal_item_id)->sole();
    expect($budget->total_approved_allocation)->toBe('1600.00')
        ->and($childRow->approved_allocation)->toBe('0.00')->and($childRow->detail['expense']['exercise_year'])->toBe(2026)
        ->and($childRow->detail['expense']['active_estimate_lines'])->toBe([])
        ->and($next->refresh()->allocation())->toBe('1850.00')
        ->and($manual->lines()->where('type', 'actual')->sole()->getAttributes())->toBe($actual)
        ->and($line->fresh()->quantity)->toBe('3.000000')->and($line->fresh()->unit_of_measure)->toBe('pezzi');
});

it('keeps a new child decision when its live parent is reloaded without withdrawing its creation', function (): void {
    $f = mixedContractFixture();
    extract($f);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $parent = $proposal->items()->where('contract_id', $contract->id)->sole();
    $child = app(PlanExpense::class)->create($actor, $proposal, [
        'description' => 'Nuova figlia', 'exercise_id' => $exercise->id, 'contract_item_id' => $parent->proposal_item_id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'amount' => '300.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), $proposal->refresh()->revision);
    mixedManual($f, $exercise, [['type' => 'estimate', 'amount' => '50.00']]);
    app(RealignProposalItem::class)->execute($actor, $proposal->refresh(), $parent, ProposalRealignmentChoice::Reload, null, [], (string) Str::uuid(), $proposal->refresh()->revision);
    expect($child->fresh()->status->value)->toBe('active');
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    expect($budget->total_approved_allocation)->toBe('1550.00');
});

it('creates a new contract with two children exactly once and rolls the graph back if the second child fails', function (): void {
    $f = mixedContractFixture('2026-01-01', false);
    extract($f);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $next, (string) Str::uuid());
    $created = app(PlanContract::class)->create($actor, $proposal, [
        'title' => 'Nuovo accordo', 'supplier_id' => $supplier->id, 'contractual_start_date' => '2026-10-10',
        'exercise_id' => $next->id,
    ], (string) Str::uuid(), $proposal->refresh()->revision);
    app(PlanContract::class)->execute($actor, $proposal->refresh(), $created->item, ProposalActionType::AddContractCondition, [
        'amount' => '1200.00', 'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-10-10',
    ], null, (string) Str::uuid(), $proposal->refresh()->revision);
    foreach (['300.00', '450.00'] as $amount) {
        app(PlanExpense::class)->create($actor, $proposal->refresh(), [
            'description' => 'Setup '.$amount, 'exercise_id' => $next->id, 'contract_item_id' => $created->item->proposal_item_id,
            'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'amount' => $amount, 'annulled' => false]],
        ], null, (string) Str::uuid(), $proposal->refresh()->revision);
    }
    $count = 0;
    $id = (string) Str::uuid();
    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $id, [], [], function (string $point) use (&$count): void {
        if ($point === 'after_expense' && ++$count === 2) {
            throw new RuntimeException('second child');
        }
    }))->toThrow(RuntimeException::class)->and(Contract::query()->count())->toBe(1)->and(Expense::query()->count())->toBe(0);
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $id);
    expect($budget->total_approved_allocation)->toBe('1950.00')->and($budget->rows()->where('source_type', 'expense')->count())->toBe(2);
});

it('retains the first economic use after the last estimate is annulled and accepts estimates for a planned contract', function (): void {
    $f = mixedContractFixture('2027-01-01', false);
    extract($f);
    expect($contract->fresh()->economic_use_recorded)->toBeFalse();
    $manual = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '200.00']]);
    $line = $manual->lines()->sole();
    app(SetExpenseLineActive::class)->execute($actor, $line, false, (string) Str::uuid());
    $other = Supplier::factory()->for($company)->create();
    expect($manual->fresh()->allocation())->toBe('0.00')->and($contract->fresh()->economic_use_recorded)->toBeTrue()
        ->and(fn () => app(UpdateContract::class)->execute($actor, $contract, ['title' => $contract->title, 'supplier_id' => $other->id], (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('requires residual context for terminal estimates and rejects a mixed request atomically when its actual is invalid', function (): void {
    $f = mixedContractFixture('2027-01-01', false);
    extract($f);
    $input = ['exercise_id' => $next->id, 'contract_id' => $contract->id, 'description' => 'Mista',
        'lines' => [['type' => 'estimate', 'amount' => '300.00'], ['type' => 'actual', 'amount' => '10.00']]];
    expect(fn () => app(CreateExpense::class)->execute($actor, $company, $input, (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);
    app(CancelContract::class)->execute($actor, $contract, 'Accordo annullato', $contract->revision, (string) Str::uuid());
    $input['lines'] = [['type' => 'estimate', 'amount' => '300.00']];
    expect(fn () => app(CreateExpense::class)->execute($actor, $company, $input, (string) Str::uuid()))->toThrow(ValidationException::class);
    $expense = app(CreateExpense::class)->execute($actor, $company, [...$input, 'residual_estimate' => true, 'activity_note' => 'Costo di disdetta'], (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $expense->lines()->sole(), false, (string) Str::uuid());
    expect($expense->fresh()->allocation())->toBe('0.00')->and($contract->fresh()->stateAtDate('2026-10-04')->value)->toBe('cancelled');
});

it('adds a first future condition through the edit confirmation without moving its date', function (): void {
    $f = mixedContractFixture('2026-01-01', false);
    extract($f);
    $this->actingAs($actor);
    Filament::setTenant($company->tenantCompany);
    $component = Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()]);
    $component->callFormComponentAction('conditions', 'add');
    $key = array_key_last($component->get('data.conditions'));
    $component->set("data.conditions.{$key}.amount", '100.00')->set("data.conditions.{$key}.cycle", 'monthly')
        ->set("data.conditions.{$key}.attribution_mode", 'cycle_start')->set("data.conditions.{$key}.valid_from", '10/10/2026')
        ->call('save')->assertHasNoFormErrors()->assertActionMounted('interpretChanges')
        ->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('confirmChanges')
        ->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($contract->conditions()->sole()->validFrom()->toDateString())->toBe('2026-10-10');
});

it('keeps Sophos in its real cost year without creating a recurring condition or copying its manual estimate', function (): void {
    $f = mixedContractFixture('2025-02-22', false);
    extract($f);
    $contract->update(['next_expiry_date' => '2028-02-22', 'renewal_anchor_date' => '2028-02-22']);
    $manual = mixedManual($f, $exercise, [['type' => 'estimate', 'amount' => '2621.34', 'quantity' => '18', 'unit_amount' => '145.63', 'unit_of_measure' => 'licenze']]);
    expect($exercise->refresh()->allocation())->toBe('2621.34')->and($next->refresh()->allocation())->toBe('0.00')
        ->and($contract->conditions()->count())->toBe(0)->and($manual->actual())->toBe('0.00');
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['projects' => []]);
    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, [...$prepared['input'], 'review_fingerprint' => $prepared['execution_fingerprint'], 'warnings_acknowledged' => true, 'confirmed' => true], (string) Str::uuid());
    expect($snapshot->total_final_allocation)->toBe('2621.34')->and($next->refresh()->allocation())->toBe('0.00');
    $contract->conditions()->create(['company_id' => $company->id, 'amount' => '999.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-01-01', 'created_by_id' => $actor->id]);
    $history = ContractInfolist::allocationDetail($contract->fresh(), 2025);
    expect($history['composition'])->toBe([])->and($history['manual_expenses'])->toHaveCount(1)
        ->and($history['reference_label'])->toContain('Snapshot di Chiusura');
});

it('reconciles contracts projects standalone expenses suppliers and direct cost centers in current and Budget references', function (bool $carryover): void {
    $f = mixedContractFixture();
    extract($f);
    $manual = mixedManual($f, $exercise, [['type' => 'estimate', 'amount' => '300.00'], ['type' => 'actual', 'amount' => '900.00']]);
    $root = CostCenter::factory()->for($company)->create();
    $leaf = CostCenter::factory()->for($company)->create(['parent_id' => $root->id]);
    $contract->classifications()->where('exercise_id', $exercise->id)->update(['cost_center_id' => $leaf->id]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2024-01-01']);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $root->id]);
    $supplier2 = Supplier::factory()->for($company)->create();
    $projectExpense = app(CreateExpense::class)->execute($actor, $company, ['exercise_id' => $exercise->id, 'project_id' => $project->id, 'supplier_id' => $supplier2->id, 'description' => 'Progetto', 'lines' => [['type' => 'estimate', 'amount' => '300.00']]], (string) Str::uuid());
    app(CreateExpense::class)->execute($actor, $company, ['exercise_id' => $exercise->id, 'description' => 'Autonoma', 'lines' => [['type' => 'estimate', 'amount' => '50.00']]], (string) Str::uuid());
    if ($carryover) {
        $previous = Exercise::factory()->for($company)->create(['year' => 2024]);
        ProjectDeferral::factory()->carryover('40.00')->create(['company_id' => $company->id, 'project_id' => $project->id, 'source_exercise_id' => $previous->id, 'destination_exercise_id' => $exercise->id]);
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $budget = app(ApproveProposal::class)->execute($actor, $proposal, (string) Str::uuid());
    $expected = $carryover ? '1890.00' : '1850.00';
    expect($budget->total_approved_allocation)->toBe($expected);
    foreach (['current', 'budget'] as $type) {
        $reference = ['type' => $type, 'exercise_id' => $exercise->id];
        if ($type === 'budget') {
            $reference['budget_snapshot_id'] = $budget->id;
        }
        $report = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray([
            'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'suppliers', 'final_reference' => $reference,
        ]));
        $sources = $report->sources;
        $sources = array_values(array_filter($sources));
        $aggregator = app(ReportAggregator::class);
        expect($aggregator->executive($sources)['allocation'])->toBe($expected)
            ->and(Decimal::sum(collect($aggregator->suppliers($sources))->pluck('allocation')))->toBe($expected);
        $suppliers = collect($aggregator->suppliers($sources))->keyBy('key');
        expect($suppliers['supplier:'.$supplier->id]['allocation'])->toBe('1500.00')
            ->and($suppliers['supplier:'.$supplier2->id]['allocation'])->toBe('300.00');
        $centers = collect($aggregator->costCenters($sources))->keyBy('key');
        expect(Decimal::sum($centers->pluck('direct_allocation')))->toBe($expected)
            ->and($centers['cost-center:'.$leaf->id]['direct_allocation'])->toBe('1500.00')
            ->and($centers['cost-center:'.$root->id]['direct_allocation'])->toBe($carryover ? '340.00' : '300.00')
            ->and($centers['unclassified']['direct_allocation'])->toBe('50.00');
    }
})->with([false, true]);

it('rejects a first condition before the agreement in a closed year or after its preview becomes stale', function (): void {
    $f = mixedContractFixture('2026-01-01', false);
    extract($f);
    $action = app(CreateContractCondition::class);
    $input = ['amount' => '100.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2025-12-01', 'reason' => 'Prima decorrenza'];
    expect(fn () => $action->preview($actor, $contract, $input))->toThrow(ValidationException::class);
    $input['valid_from'] = '2026-10-10';
    $preview = $action->preview($actor, $contract, $input);
    mixedManual($f, $next, [['type' => 'estimate', 'amount' => '20.00']]);
    expect(fn () => $action->execute($actor, $contract->fresh(), $input, (string) Str::uuid(), ContractImpactFingerprint::make($preview)))->toThrow(ValidationException::class)
        ->and($contract->conditions()->count())->toBe(0);
    closeExerciseFixture($next, $actor);
    expect(fn () => $action->preview($actor, $contract->fresh(), $input))->toThrow(ValidationException::class);
});

it('records first economic use for an actual zero and a Budget zero while leaving a never used contract editable', function (): void {
    $f = mixedContractFixture('2026-01-01', false);
    extract($f);
    $other = Supplier::factory()->for($company)->create();
    app(UpdateContract::class)->execute($actor, $contract, ['title' => $contract->title, 'supplier_id' => $other->id], (string) Str::uuid());
    expect($contract->fresh()->economic_use_recorded)->toBeFalse();
    $manual = mixedManual($f, $next, [['type' => 'actual', 'amount' => '0.00', 'note' => 'Registrazione nulla']]);
    expect($contract->fresh()->economic_use_recorded)->toBeTrue();
    $unused = app(CreateContract::class)->execute($actor, $company, ['title' => 'Budget zero', 'supplier_id' => $supplier->id, 'contractual_start_date' => '2026-01-01', 'conditions' => []], (string) Str::uuid());
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $next, (string) Str::uuid());
    app(ApproveProposal::class)->execute($actor, $proposal, (string) Str::uuid());
    expect($unused->fresh()->economic_use_recorded)->toBeTrue();
});

it('reactivates without conditions or copied costs and rejects a first rate before the relevant reactivation', function (): void {
    $f = mixedContractFixture('2026-01-01', false);
    extract($f);
    $manual = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '300.00']]);
    app(CeaseContract::class)->execute($actor, $contract->refresh(), '2026-06-15', 'Fine accordo', $contract->revision, (string) Str::uuid());
    app(ReactivateContract::class)->execute($actor, $contract->refresh(), [
        'start_date' => '2026-09-01', 'reason' => 'Nuovo accordo', 'expected_revision' => $contract->revision,
    ], (string) Str::uuid());
    expect($contract->conditions()->count())->toBe(0)->and($contract->expenses()->count())->toBe(1)
        ->and($manual->fresh()->allocation())->toBe('300.00');
    $action = app(CreateContractCondition::class);
    $input = ['amount' => '100.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-05-01', 'reason' => 'Primo canone'];
    expect(fn () => $action->preview($actor, $contract->fresh(), $input))->toThrow(ValidationException::class);
    $input['valid_from'] = '2026-09-01';
    $preview = $action->preview($actor, $contract->fresh(), $input);
    $action->execute($actor, $contract->fresh(), $input, (string) Str::uuid(), ContractImpactFingerprint::make($preview));
    expect($next->refresh()->allocation())->toBe('700.00');
});

it('preserves started cycles and manual estimates after cessation for both attribution modes', function (string $mode): void {
    $f = mixedContractFixture('2026-01-01');
    extract($f);
    $contract->conditions()->update(['attribution_mode' => $mode]);
    app(RecalculateContractEstimates::class)->execute($actor, $contract->refresh(), [$exercise, $next], (string) Str::uuid());
    $manual = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '300.00']]);
    app(CeaseContract::class)->execute($actor, $contract->refresh(), '2026-06-15', 'Fine accordo', $contract->revision, (string) Str::uuid());
    expect($next->refresh()->allocation())->toBe('900.00')->and($manual->fresh()->allocation())->toBe('300.00')
        ->and($contract->expenses()->where('origin', 'system')->where('exercise_id', $next->id)->sole()->allocation())->toBe('600.00');
})->with(['cycle_start', 'cycle_end']);

it('rejects foreign system and closed-year lines in guided cancellation without changing the contract', function (): void {
    $f = mixedContractFixture('2027-01-01', false);
    extract($f);
    $open = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '100.00']]);
    $closed = mixedManual($f, $exercise, [['type' => 'estimate', 'amount' => '200.00']]);
    closeExerciseFixture($exercise, $actor);
    $foreignExpense = Expense::factory()->forExercise($next)->create();
    $foreignLine = ExpenseLine::factory()->for($foreignExpense)->create(['amount' => '50.00']);
    $system = Expense::factory()->forExercise($next)->create(['contract_id' => $contract->id, 'origin' => 'system']);
    $systemLine = ExpenseLine::factory()->for($system)->create(['amount' => '0.00']);
    $action = app(CancelContract::class);
    $preview = $action->preview($actor, $contract->refresh());
    expect($preview['readonly'])->toHaveCount(1);
    foreach ([$foreignLine->id, $systemLine->id, $closed->lines()->sole()->id] as $lineId) {
        expect(fn () => $action->execute($actor, $contract, 'Annullamento', $contract->revision, (string) Str::uuid(), [$lineId], ContractImpactFingerprint::make($preview)))
            ->toThrow(ValidationException::class);
    }
    expect($contract->fresh()->stateAtDate('2026-10-04')->value)->toBe('planned')
        ->and($open->fresh()->allocation())->toBe('100.00')->and($closed->fresh()->allocation())->toBe('200.00');
});

it('requires line permission to cancel a manual estimate even when the actor can update the contract', function (): void {
    $f = mixedContractFixture('2027-01-01', false);
    extract($f);
    $manual = mixedManual($f, $next, [['type' => 'estimate', 'amount' => '100.00']]);
    $limited = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $limited, 'permissions' => ['Update:Contract']]);
    $action = app(CancelContract::class);
    $preview = $action->preview($limited, $contract->refresh());
    expect($preview['candidates'][0]['can_update'])->toBeFalse();
    expect(fn () => $action->execute($limited, $contract, 'Annullamento', $contract->revision, (string) Str::uuid(), [$manual->lines()->sole()->id], ContractImpactFingerprint::make($preview)))
        ->toThrow(AuthorizationException::class);
    expect($contract->fresh()->stateAtDate('2026-10-04')->value)->toBe('planned')->and($manual->fresh()->allocation())->toBe('100.00');
});
