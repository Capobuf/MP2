<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\Actions\Reporting\BuildReport;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Domain\LateCorrections\HistoricalErrorKind;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSnapshot;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\LateCorrection;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return list<array<string, mixed>> */
function backupReportDefinitions(Company $company): array
{
    $closed = $company->exercises()->where('year', 2025)->sole();
    $comparison = $company->exercises()->where('year', 2026)->sole();
    $budgets = $company->budgets()->where('exercise_id', $closed->id)->orderBy('version')->get();
    $comparisonBudget = $company->budgets()->where('exercise_id', $comparison->id)->sole();
    $budgetRef = fn (BudgetSnapshot $budget): array => ['type' => 'budget', 'exercise_id' => $budget->exercise_id, 'budget_snapshot_id' => $budget->id];

    return [
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'annual_executive', 'actual_reference' => 'current_knowledge', 'initial_reference' => ['type' => 'closing', 'exercise_id' => $closed->id], 'final_reference' => ['type' => 'current_knowledge', 'exercise_id' => $closed->id]],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'budget_actual', 'actual_reference' => 'current_knowledge', 'initial_reference' => $budgetRef($budgets[0]), 'final_reference' => ['type' => 'current_knowledge', 'exercise_id' => $closed->id]],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'budget_current_allocation', 'initial_reference' => $budgetRef($budgets[0]), 'final_reference' => ['type' => 'current', 'exercise_id' => $closed->id]],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'operational_variance'],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'budget_versions', 'initial_reference' => $budgetRef($budgets[0]), 'final_reference' => $budgetRef($budgets[1])],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'comparison_exercise_id' => $comparison->id, 'kind' => 'exercises', 'initial_reference' => $budgetRef($budgets[0]), 'final_reference' => $budgetRef($comparisonBudget)],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'carryovers'],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'contracts'],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'projects'],
        ['company_id' => $company->id, 'exercise_id' => $closed->id, 'kind' => 'suppliers'],
    ];
}

function semanticBackupReportValue(mixed $value): mixed
{
    if ($value instanceof ReportSource) {
        return [
            'source_type' => $value->sourceType, 'derived' => $value->copiedFromOriginKey !== null,
            'label' => $value->label, 'summary' => $value->summary, 'supplier_label' => $value->supplierLabel,
            'cost_center_label' => $value->costCenterLabel, 'state' => $value->state,
            'allocation' => $value->allocation, 'actual' => $value->actual, 'has_actuals' => $value->hasActuals,
            'carryover' => $value->carryover, 'residual' => $value->residual, 'saving' => $value->saving, 'unused' => $value->unused,
            'detail' => semanticBackupReportValue($value->detail),
            'corrections' => semanticBackupReportValue($value->corrections),
            'annotations' => semanticBackupReportValue($value->annotations),
        ];
    }
    if ($value instanceof BackedEnum) {
        return $value->value;
    }
    if (is_object($value)) {
        return semanticBackupReportValue(get_object_vars($value));
    }
    if (! is_array($value)) {
        return $value;
    }
    $result = [];
    foreach ($value as $key => $item) {
        $name = (string) $key;
        if (in_array($name, ['id', 'company_id', 'exercise_id', 'origin_id', 'origin_key', 'copied_from_origin_key', 'generated_at', 'created_at'], true)
            || ($name === 'key' && is_string($item) && preg_match('/^[a-z_]+:\d+$/', $item))
            || str_ends_with($name, '_id') || str_ends_with($name, '_ids') || str_ends_with($name, '_revision')) {
            continue;
        }
        $result[$key] = semanticBackupReportValue($item);
    }

    if (array_is_list($result)) {
        usort($result, fn (mixed $left, mixed $right): int => json_encode($left) <=> json_encode($right));
    }

    return $result;
}

/** @return array<string, mixed> */
function semanticBackupReport(ReportResult $report): array
{
    return [
        'header' => semanticBackupReportValue($report->header),
        'totals' => $report->totals,
        'sources' => semanticBackupReportValue($report->sources),
        'comparisons' => semanticBackupReportValue($report->comparisons),
        'category_counts' => $report->categoryCounts,
        'label_counts' => $report->labelCounts,
        'sections' => semanticBackupReportValue($report->sections),
    ];
}

/** @return list<string> */
function backupReportDifferences(mixed $before, mixed $after, string $path = ''): array
{
    if (gettype($before) !== gettype($after)) {
        return ["$path type ".gettype($before).' != '.gettype($after)];
    }
    if (! is_array($before)) {
        return $before === $after ? [] : ["$path ".json_encode($before).' != '.json_encode($after)];
    }
    $differences = [];
    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
        $child = $path === '' ? (string) $key : $path.'.'.$key;
        if (! array_key_exists($key, $before) || ! array_key_exists($key, $after)) {
            $differences[] = "$child missing on one side";
        } else {
            $differences = [...$differences, ...backupReportDifferences($before[$key], $after[$key], $child)];
        }
        if (count($differences) >= 20) {
            break;
        }
    }

    return array_slice($differences, 0, 20);
}

it('keeps all ten canonical S11 report families semantically equivalent after restore', function (): void {
    CarbonImmutable::setTestNow('2026-08-30 10:00:00 Europe/Rome');
    $company = Company::factory()->create(['name' => 'Reporting Portabile', 'timezone' => 'Europe/Rome']);
    $actor = User::factory()->platformAdmin()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::VIEW]);
    $closed = Exercise::factory()->for($company)->create(['year' => 2025]);
    $next = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore Storico']);
    $project = Project::factory()->for($company)->create(['title' => 'Progetto Storico', 'initial_state' => 'open']);
    $contract = Contract::factory()->for($company)->for($supplier)->create(['title' => 'Contratto Storico', 'contractual_start_date' => '2025-01-01']);

    $standalone = Expense::factory()->forExercise($closed)->create(['description' => 'Autonoma']);
    ExpenseLine::factory()->for($standalone)->create(['amount' => '50.00']);
    $originalActual = ExpenseLine::factory()->for($standalone)->actual()->create(['amount' => '40.00']);
    $projectExpense = Expense::factory()->forExercise($closed)->for($project)->create(['description' => 'Piano Progetto']);
    ExpenseLine::factory()->for($projectExpense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($projectExpense)->actual()->create(['amount' => '80.00']);
    $contractExpense = Expense::factory()->forExercise($closed)->for($contract)->create(['description' => 'Piano Contratto', 'supplier_id' => $supplier->id]);
    ExpenseLine::factory()->for($contractExpense)->create(['amount' => '200.00']);
    ExpenseLine::factory()->for($contractExpense)->actual()->create(['amount' => '160.00']);
    $nextExpense = Expense::factory()->forExercise($next)->create(['description' => 'Anno successivo']);
    ExpenseLine::factory()->for($nextExpense)->create(['amount' => '20.00']);
    ProjectDeferral::factory()->create([
        'company_id' => $company->id, 'project_id' => $project->id, 'source_exercise_id' => $closed->id,
        'destination_exercise_id' => $next->id, 'mode' => 'carryover', 'carryover_amount' => '10.00',
        'carryover_state' => 'consolidated', 'reprogrammed_amount' => '0.00',
    ]);

    $sourceModels = [['expense', $standalone, 'Autonoma', '50.00'], ['project', $project, 'Progetto Storico', '100.00'], ['contract', $contract, 'Contratto Storico', '200.00']];
    $proposal1 = Proposal::factory()->for($company)->for($closed)->for($actor, 'creator')->create(['status' => 'approved']);
    $budget1 = BudgetSnapshot::factory()->for($proposal1)->create(['company_id' => $company->id, 'exercise_id' => $closed->id, 'approved_by_id' => $actor->id, 'total_approved_allocation' => '350.00']);
    $proposal2 = Proposal::factory()->for($company)->for($closed)->for($actor, 'creator')->create(['status' => 'approved', 'purpose' => 'revision', 'reference_budget_id' => $budget1->id]);
    $budget2 = BudgetSnapshot::factory()->for($proposal2)->create(['company_id' => $company->id, 'exercise_id' => $closed->id, 'approved_by_id' => $actor->id, 'version' => 2, 'purpose' => 'revision', 'previous_budget_id' => $budget1->id, 'total_approved_allocation' => '380.00']);
    foreach ([[$budget1, ['50.00', '100.00', '200.00']], [$budget2, ['60.00', '110.00', '210.00']]] as [$budget, $amounts]) {
        foreach ($sourceModels as $index => [$type, $model, $label]) {
            BudgetSourceRow::factory()->for($budget, 'budget')->create([
                'company_id' => $company->id, 'source_type' => $type, 'origin_id' => $model->id,
                'origin_key' => $model->originKey(), 'label' => $label, 'supplier_id' => $type === 'contract' ? $supplier->id : null,
                'supplier_label' => $type === 'contract' ? $supplier->legal_name : null, 'cost_center_label' => 'Non classificato',
                'approved_estimates' => $amounts[$index], 'approved_allocation' => $amounts[$index], 'detail' => ['schema_version' => 1],
            ]);
        }
    }
    $nextProposal = Proposal::factory()->for($company)->for($next)->for($actor, 'creator')->create(['status' => 'approved']);
    $nextBudget = BudgetSnapshot::factory()->for($nextProposal)->create(['company_id' => $company->id, 'exercise_id' => $next->id, 'approved_by_id' => $actor->id, 'total_approved_allocation' => '20.00']);
    BudgetSourceRow::factory()->for($nextBudget, 'budget')->create([
        'company_id' => $company->id, 'origin_id' => $nextExpense->id, 'origin_key' => $nextExpense->originKey(),
        'label' => 'Anno successivo', 'approved_estimates' => '20.00', 'approved_allocation' => '20.00',
    ]);

    $closing = ClosingSnapshot::query()->create([
        'company_id' => $company->id, 'company_name' => $company->name, 'exercise_id' => $closed->id, 'exercise_year' => 2025,
        'closed_at' => now(), 'closed_by_id' => $actor->id, 'initial_budget_id' => $budget1->id, 'current_budget_id' => $budget2->id,
        'total_final_allocation' => '350.00', 'total_closing_actual' => '280.00', 'total_operational_variance' => '-70.00',
        'total_consolidated_carryover' => '10.00', 'accepted_warnings' => [], 'applied_settings' => ['timezone' => 'Europe/Rome'],
        'next_exercise_disposition' => 'already_existed', 'next_exercise_id' => $next->id, 'operation_id' => (string) Str::uuid(),
    ]);
    $closed->update(['status' => 'closed']);
    foreach ([['expense', $standalone, 'Autonoma', '50.00', '40.00'], ['project', $project, 'Progetto Storico', '100.00', '80.00'], ['contract', $contract, 'Contratto Storico', '200.00', '160.00']] as [$type, $model, $label, $allocation, $actual]) {
        ClosingSourceRow::query()->create([
            'company_id' => $company->id, 'closing_snapshot_id' => $closing->id, 'source_type' => $type,
            'origin_id' => $model->id, 'origin_key' => $model->originKey(), 'label' => $label,
            'supplier_id' => $type === 'contract' ? $supplier->id : null, 'supplier_label' => $type === 'contract' ? $supplier->legal_name : null,
            'cost_center_label' => 'Non classificato', 'end_state' => 'active', 'has_actuals' => true,
            'final_estimates' => $allocation, 'received_carryover' => $type === 'project' ? '10.00' : '0.00',
            'final_allocation' => $allocation, 'closing_actual' => $actual,
            'operational_variance' => bcsub($actual, $allocation, 2), 'detail_version' => 1,
            'detail' => ['saving' => '5.00', 'residual' => '2.00', 'unused' => '3.00', 'consolidated_carryover' => $type === 'project' ? '10.00' : '0.00'],
        ]);
    }
    foreach ([['10.00', 'Aumento'], ['-5.00', 'Riduzione']] as [$amount, $reason]) {
        $line = ExpenseLine::factory()->for($standalone)->actual()->create(['amount' => $amount, 'note' => $reason]);
        LateCorrection::query()->create([
            'company_id' => $company->id, 'exercise_id' => $closed->id, 'closing_snapshot_id' => $closing->id,
            'expense_id' => $standalone->id, 'expense_line_id' => $line->id, 'original_expense_line_id' => $originalActual->id,
            'recorded_by_id' => $actor->id, 'operation_id' => (string) Str::uuid(), 'reason' => $reason,
            'belongs_to_closed_exercise' => true, 'source_type' => 'expense', 'source_origin_id' => $standalone->id,
            'source_origin_key' => $standalone->originKey(), 'source_label' => 'Autonoma', 'owner_context' => ['schema_version' => 1],
        ]);
    }
    HistoricalErrorAnnotation::query()->create([
        'company_id' => $company->id, 'exercise_id' => $closed->id, 'closing_snapshot_id' => $closing->id,
        'recorded_by_id' => $actor->id, 'operation_id' => (string) Str::uuid(), 'kind' => HistoricalErrorKind::CostCenter,
        'reason' => 'Classificazione contestata', 'recorded_facts_version' => 1, 'recorded_facts' => ['value' => 'Storico'],
        'believed_correct_facts_version' => 1, 'believed_correct_facts' => ['value' => 'Corretto'],
        'affected_sources_version' => 1, 'affected_sources' => [['type' => 'expense', 'id' => $standalone->id, 'origin_key' => $standalone->originKey(), 'label' => 'Autonoma']],
    ]);

    $generatedAt = CarbonImmutable::now();
    $before = [];
    foreach (backupReportDefinitions($company) as $definition) {
        $report = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray($definition, $generatedAt));
        $before[$definition['kind']] = semanticBackupReport($report);
    }

    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    try {
        $restored = app(ImportBusinessBackup::class)->execute($actor, app(BusinessBackupValidator::class)->validate($artifact['path']));
    } finally {
        @unlink($artifact['path']);
    }

    $after = [];
    foreach (backupReportDefinitions($restored) as $definition) {
        $report = app(BuildReport::class)->execute($actor, ReportDefinition::fromArray($definition, $generatedAt));
        $after[$definition['kind']] = semanticBackupReport($report);
    }

    $differences = [];
    foreach ($before as $kind => $report) {
        $kindDifferences = backupReportDifferences($report, $after[$kind] ?? null);
        if ($kindDifferences !== []) {
            $differences[$kind] = $kindDifferences;
        }
    }
    expect(array_keys($after))->toBe(['annual_executive', 'budget_actual', 'budget_current_allocation', 'operational_variance', 'budget_versions', 'exercises', 'carryovers', 'contracts', 'projects', 'suppliers'])
        ->and($differences)->toBe([]);
});
