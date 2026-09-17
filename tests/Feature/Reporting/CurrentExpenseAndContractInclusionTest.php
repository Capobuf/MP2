<?php

use App\Actions\Reporting\BuildReport;
use App\Domain\Company\AuditEventType;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportResult;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00 Europe/Rome'));
});

function annualInclusionSource(string $type, Exercise $exercise): Expense|Contract
{
    if ($type === 'expense') {
        $expense = Expense::factory()->forExercise($exercise)->create();
        ExpenseLine::factory()->for($expense)->create(['amount' => '0.00', 'note' => 'Stima nulla']);

        return $expense;
    }

    $contract = Contract::factory()->create([
        'company_id' => $exercise->company_id,
        'contractual_start_date' => '2020-01-01',
        'next_expiry_date' => '2022-12-31',
        'renewal_anchor_date' => '2022-12-31',
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation',
        'reason' => 'Cessazione storica',
        'declared_contractual_date' => '2022-12-30',
        'state_change_date' => '2022-12-31',
    ]);

    return $contract;
}

function annualInclusionReport(Exercise $exercise, string $reference = 'current'): ReportResult
{
    return app(BuildReport::class)->execute(s11ReportingViewer($exercise->company), ReportDefinition::fromArray([
        'company_id' => $exercise->company_id,
        'exercise_id' => $exercise->id,
        'kind' => 'annual_executive',
        'actual_reference' => $reference,
        'final_reference' => ['type' => $reference, 'exercise_id' => $exercise->id],
    ]));
}

function annualInclusionEvent(Expense|Contract $source, string $date, ?AuditEventType $type = null): void
{
    AuditEvent::query()->create([
        'company_id' => $source->company_id,
        'actor_id' => User::factory()->create()->id,
        'event_type' => $type ?? ($source instanceof Expense ? AuditEventType::ExpenseRestored : AuditEventType::ContractRestored),
        'subject_type' => $source::class,
        'subject_id' => $source->id,
        'affected_exercise_ids' => [],
        'effective_from' => $date,
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ]);
}

it('excludes expenses and contracts without an annual inclusion reason', function (string $type, string $history) {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $source = annualInclusionSource($type, $exercise);
    if ($history === 'old budget') {
        $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
        $proposal = Proposal::factory()->for($company)->for($previous, 'exercise')->create();
        $budget = BudgetSnapshot::factory()->for($proposal)->create();
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'source_type' => $type, 'origin_id' => $source->id, 'origin_key' => $source->originKey(),
        ]);
    } elseif ($history === 'old restoration') {
        annualInclusionEvent($source, '2025-12-31');
    } elseif ($history === 'zero actual') {
        $expense = $source instanceof Expense ? $source : Expense::factory()->forExercise($exercise)->for($source, 'contract')->create();
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '0.00', 'note' => 'Effettivo nullo']);
    } elseif ($history === 'annulled actual') {
        $expense = $source instanceof Expense ? $source : Expense::factory()->forExercise($exercise)->for($source, 'contract')->create();
        ExpenseLine::factory()->for($expense)->actual()->annulled()->create(['amount' => '100.00']);
    }

    foreach (['current', 'current_knowledge'] as $reference) {
        $result = annualInclusionReport($exercise, $reference);
        expect($result->sources)->toBeEmpty()->and($result->totals['source_count'])->toBe(0);
    }
})->with(['expense', 'contract'])->with(['none', 'old budget', 'old restoration', 'zero actual', 'annulled actual']);

it('keeps expenses and contracts for each shared canonical inclusion reason', function (string $type, string $reason) {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $source = annualInclusionSource($type, $exercise);
    if (in_array($reason, ['allocation', 'actual', 'net zero actuals'], true)) {
        $expense = $source instanceof Expense ? $source : Expense::factory()->forExercise($exercise)->for($source, 'contract')->create();
        ExpenseLine::factory()->for($expense)->create([
            'type' => $reason === 'allocation' ? 'estimate' : 'actual', 'amount' => '100.00',
        ]);
        if ($reason === 'net zero actuals') {
            ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-100.00', 'note' => 'Rimborso']);
        }
    } elseif ($reason === 'budget') {
        $proposal = Proposal::factory()->for($company)->for($exercise)->create();
        $budget = BudgetSnapshot::factory()->for($proposal)->create();
        BudgetSourceRow::factory()->for($budget, 'budget')->create([
            'source_type' => $type, 'origin_id' => $source->id, 'origin_key' => $source->originKey(),
        ]);
    } elseif ($reason === 'restoration') {
        annualInclusionEvent($source, '2026-01-01');
    } elseif ($reason === 'annotation') {
        $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
        closeExerciseFixture($previous, s11ReportingViewer($company));
        HistoricalErrorAnnotation::factory()->forExercise($previous)->create([
            'affected_sources' => [[
                'type' => $type, 'id' => $source->id, 'origin_key' => $source->originKey(), 'label' => 'Sorgente storica',
            ]],
        ]);
    }
    if ($source instanceof Contract) {
        $source->update(['archived_at' => now()]);
    }

    $result = annualInclusionReport($exercise);
    expect(collect($result->sources)->pluck('originKey')->all())->toBe([$source->originKey()])
        ->and($result->totals['source_count'])->toBe(1)
        ->and($result->sources[0]->allocation)->toBe($reason === 'allocation' ? '100.00' : '0.00')
        ->and($result->sources[0]->actual)->toBe($reason === 'actual' ? '100.00' : '0.00')
        ->and($result->sources[0]->hasActuals)->toBe(in_array($reason, ['actual', 'net zero actuals'], true));
})->with(['expense', 'contract'])->with(['allocation', 'actual', 'net zero actuals', 'budget', 'restoration', 'annotation']);

it('includes only reversals in the selected year', function (string $date, bool $included) {
    $exercise = Exercise::factory()->create(['year' => 2026]);
    $expense = annualInclusionSource('expense', $exercise);
    $expense->update(['reversed_at' => $date]);
    annualInclusionEvent($expense, substr($date, 0, 10), AuditEventType::ExpenseReversed);

    expect(annualInclusionReport($exercise)->sources)->toHaveCount($included ? 1 : 0);
})->with([['2026-12-31 12:00:00', true], ['2025-12-31 12:00:00', false]]);

it('requires a valid overlapping contract condition', function (string $from, ?string $to, bool $annulled, bool $included) {
    $exercise = Exercise::factory()->create(['year' => 2026]);
    $contract = annualInclusionSource('contract', $exercise);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => $from, 'valid_to' => $to, 'amount' => '0.00', 'reason' => 'Periodo gratuito',
        'annulled_at' => $annulled ? now() : null,
        'annulled_by_id' => $annulled ? User::factory()->create()->id : null,
    ]);

    expect(annualInclusionReport($exercise)->sources)->toHaveCount($included ? 1 : 0);
})->with([
    ['2025-01-01', '2026-01-01', false, true],
    ['2026-12-31', null, false, true],
    ['2025-01-01', '2025-12-31', false, false],
    ['2027-01-01', null, false, false],
    ['2026-01-01', null, true, false],
]);

it('includes non-annulled annual contract events using contractual and state dates', function (string $declared, string $effective, bool $annulled, bool $included) {
    $exercise = Exercise::factory()->create(['year' => 2026]);
    $contract = annualInclusionSource('contract', $exercise);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'reason' => 'Riattivazione pianificata',
        'type' => 'reactivation', 'declared_contractual_date' => $declared, 'state_change_date' => $effective,
        'annulled_at' => $annulled ? now() : null,
        'annulled_by_id' => $annulled ? User::factory()->create()->id : null,
        'annulment_reason' => $annulled ? 'Annullata' : null,
    ]);

    expect(annualInclusionReport($exercise)->sources)->toHaveCount($included ? 1 : 0);
})->with([
    ['2026-12-31', '2027-01-01', false, true],
    ['2025-12-31', '2026-01-01', false, true],
    ['2026-12-31', '2026-12-31', true, false],
    ['2027-01-01', '2027-01-01', false, false],
]);

it('uses the annual contract state and retains a non-renewing expiry in its year', function () {
    $company = Company::factory()->create();
    $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
    $current = Exercise::factory()->for($company)->create(['year' => 2026]);
    $next = Exercise::factory()->for($company)->create(['year' => 2027]);
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01']);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2025-01-01', 'automatic_renewal' => false, 'expiry_anchor_date' => '2026-01-31',
    ]);

    $planned = annualInclusionReport($previous);
    expect($planned->sources)->toHaveCount(1)->and($planned->sources[0]->state)->toBe('planned');
    $expired = annualInclusionReport($current);
    expect($expired->sources)->toHaveCount(1)->and($expired->sources[0]->state)->toBe('cessated');
    expect(annualInclusionReport($next)->sources)->toBeEmpty();
});

it('keeps active contracts without amounts', function () {
    $exercise = Exercise::factory()->create(['year' => 2026]);
    Contract::factory()->create(['company_id' => $exercise->company_id]);
    expect(annualInclusionReport($exercise)->sources)->toHaveCount(1);
});

it('preserves historical expense and contract snapshot rows', function (string $type) {
    $exercise = Exercise::factory()->create(['year' => 2025]);
    $company = $exercise->company;
    $source = annualInclusionSource($type, $exercise);
    $viewer = s11ReportingViewer($company);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create();
    $budgetRow = BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'source_type' => $type, 'origin_id' => $source->id, 'origin_key' => $source->originKey(), 'label' => 'Budget storico',
    ]);
    $snapshot = closeExerciseFixture($exercise, $viewer);
    $closingRow = ClosingSourceRow::query()->create([
        'company_id' => $company->id, 'closing_snapshot_id' => $snapshot->id,
        'source_type' => $type, 'origin_id' => $source->id, 'origin_key' => $source->originKey(),
        'label' => 'Chiusura storica', 'cost_center_label' => 'Non classificato',
        'end_state' => $type === 'expense' ? 'active' : 'cessated', 'has_actuals' => false,
        'final_estimates' => '0.00', 'received_carryover' => '0.00', 'final_allocation' => '0.00',
        'closing_actual' => '0.00', 'operational_variance' => '0.00', 'detail_version' => 1, 'detail' => [],
    ]);
    $before = [DB::table('budget_source_rows')->find($budgetRow->id), DB::table('closing_source_rows')->find($closingRow->id)];

    foreach (['budget' => 'Budget storico', 'closing' => 'Chiusura storica', 'current_knowledge' => 'Chiusura storica'] as $referenceType => $label) {
        $result = app(BuildReport::class)->execute($viewer, ReportDefinition::fromArray([
            'company_id' => $company->id, 'exercise_id' => $exercise->id, 'kind' => 'operational_variance',
            'final_reference' => ['type' => $referenceType, 'exercise_id' => $exercise->id, 'budget_snapshot_id' => $referenceType === 'budget' ? $budget->id : null],
        ]));
        expect($result->sources)->toHaveCount(1)->and($result->sources[0]->label)->toBe($label);
    }

    expect([DB::table('budget_source_rows')->find($budgetRow->id), DB::table('closing_source_rows')->find($closingRow->id)])->toEqual($before);
})->with(['expense', 'contract']);
