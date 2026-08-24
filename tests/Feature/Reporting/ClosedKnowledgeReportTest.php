<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\LateCorrections\HistoricalErrorKind;
use App\Domain\Reporting\ReportDefinition;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\LateCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps closing values immutable and composes current knowledge from separate corrections', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Storica']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    $snapshot = closeExerciseFixture($exercise, $viewer);
    ClosingSourceRow::query()->create([
        'company_id' => $company->id, 'closing_snapshot_id' => $snapshot->id,
        'source_type' => 'expense', 'origin_id' => $expense->id, 'origin_key' => $expense->originKey(),
        'label' => 'Etichetta alla Chiusura', 'cost_center_label' => 'Storico', 'end_state' => 'active',
        'has_actuals' => true, 'final_estimates' => '100.00', 'received_carryover' => '0.00',
        'final_allocation' => '100.00', 'closing_actual' => '100.00', 'operational_variance' => '0.00',
        'detail_version' => 1, 'detail' => ['saving' => '15.00', 'consolidated_carryover' => '8.00'],
    ]);
    foreach ([['30.00', 'Aumento'], ['-10.00', 'Riduzione']] as [$amount, $reason]) {
        $line = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => $amount, 'note' => $reason]);
        LateCorrection::query()->create([
            'company_id' => $company->id, 'exercise_id' => $exercise->id, 'closing_snapshot_id' => $snapshot->id,
            'expense_id' => $expense->id, 'expense_line_id' => $line->id, 'recorded_by_id' => $viewer->id,
            'operation_id' => (string) Str::uuid(), 'reason' => $reason, 'belongs_to_closed_exercise' => true,
            'source_type' => 'expense', 'source_origin_id' => $expense->id, 'source_origin_key' => $expense->originKey(),
            'source_label' => 'Etichetta alla Chiusura', 'owner_context' => [], 'supplier_context' => [],
        ]);
    }
    HistoricalErrorAnnotation::query()->create([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'closing_snapshot_id' => $snapshot->id,
        'recorded_by_id' => $viewer->id, 'operation_id' => (string) Str::uuid(), 'kind' => HistoricalErrorKind::CostCenter,
        'reason' => 'Imputazione contestata', 'recorded_facts_version' => 1, 'recorded_facts' => ['cost_center' => 'Storico'],
        'believed_correct_facts_version' => 1, 'believed_correct_facts' => ['cost_center' => 'Altro'],
        'affected_sources_version' => 1, 'affected_sources' => [[
            'type' => 'expense', 'id' => $expense->id, 'origin_key' => $expense->originKey(), 'label' => 'Etichetta alla Chiusura',
        ]],
    ]);
    $expense->update(['description' => 'Rinominata dopo la Chiusura']);

    $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'annual_executive',
        'actual_reference' => 'current_knowledge',
        'initial_reference' => ['type' => 'closing', 'exercise_id' => $exercise->id],
        'final_reference' => ['type' => 'current_knowledge', 'exercise_id' => $exercise->id],
    ]));
    $source = $result->sources[0];

    expect($source->label)->toBe('Etichetta alla Chiusura')
        ->and($source->actual)->toBe('120.00')
        ->and($source->saving)->toBe('15.00')
        ->and($source->carryover)->toBe('8.00')
        ->and($source->corrections)->toHaveCount(2)
        ->and($source->annotations)->toHaveCount(1)
        ->and($source->annotations[0]['economic_impact'])->toBe('0.00')
        ->and($result->comparisons[0]['initial_value'])->toBe('100.00')
        ->and($result->comparisons[0]['final_value'])->toBe('120.00')
        ->and($result->comparisons[0]['delta'])->toBe('20.00')
        ->and($snapshot->refresh()->total_closing_actual)->toBe('100.00');
});
