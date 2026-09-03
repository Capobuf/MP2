<?php

use App\Filament\Pages\Reports;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function reportingUiContext(Company $company, User $viewer): void
{
    test()->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);
}

function reportingUiBudget(
    Company $company,
    Exercise $exercise,
    Expense $expense,
    string $amount = '100.00',
    int $version = 1,
): BudgetSnapshot {
    $purpose = $version === 1 ? 'initial_budget' : 'revision';
    $proposal = Proposal::factory()->for($company)->for($exercise)->create([
        'purpose' => $purpose,
        'status' => 'approved',
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => $version,
        'purpose' => $purpose,
        'total_approved_allocation' => $amount,
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'origin_id' => $expense->id,
        'origin_key' => $expense->originKey(),
        'approved_allocation' => $amount,
    ]);

    return $budget;
}

it('starts with the report chooser and no implicit references or generate action', function (): void {
    $company = Company::factory()->create(['name' => 'UI Company']);
    $viewer = s11ReportingViewer($company);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->assertSuccessful()
        ->assertSet('exerciseId', null)
        ->assertSet('kind', null)
        ->assertSet('report', null)
        ->assertSee('Scegli il Report')
        ->assertSee('Vista Annuale Esecutiva')
        ->assertDontSee('Genera report');
});

it('generates the annual report automatically when explicit inputs become complete', function (): void {
    $company = Company::factory()->create(['name' => 'UI Company']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Servizio UI']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '12.00']);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->assertSet('report', null)
        ->assertSee('Completa i Riferimenti')
        ->set('actualReference', 'current')
        ->assertHasNoErrors()
        ->assertSet('definition.actual_reference', 'current')
        ->assertSee('Vista Annuale Esecutiva')
        ->assertSee('Effettivo Corrente')
        ->assertSee('Servizio UI')
        ->assertSee('Personalizza PDF')
        ->assertDontSee('Genera report');
});

it('keeps existing dashboard deep links automatic and compatible', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Da Dashboard']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '15.00']);
    reportingUiContext($company, $viewer);

    Livewire::withQueryParams([
        'exerciseId' => $exercise->id,
        'kind' => 'annual_executive',
        'actualReference' => 'current',
        'auto' => 1,
    ])->test(Reports::class)
        ->assertHasNoErrors()
        ->assertSet('definition.kind', 'annual_executive')
        ->assertSet('definition.actual_reference', 'current')
        ->assertSee('Da Dashboard');
});

it('keeps budget actual incomplete until the explicit actual reference is selected', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Licenza']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '90.00']);
    $budget = reportingUiBudget($company, $exercise, $expense);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_actual')
        ->set('budgetId', $budget->id)
        ->assertSet('report', null)
        ->assertSee('Tipo di Effettivo')
        ->set('actualReference', 'current')
        ->assertHasNoErrors()
        ->assertSet('definition.initial_reference.budget_snapshot_id', $budget->id)
        ->assertSet('definition.actual_reference', 'current')
        ->assertSee('Budget v1')
        ->assertSee('Effettivo Corrente');
});

it('refreshes supplier filtering automatically and keeps the active filter visible', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $selectedSupplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore selezionato']);
    $otherSupplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore escluso']);
    $selectedExpense = Expense::factory()->forExercise($exercise)->for($selectedSupplier)->create(['description' => 'Inclusa']);
    $otherExpense = Expense::factory()->forExercise($exercise)->for($otherSupplier)->create(['description' => 'Esclusa']);
    ExpenseLine::factory()->for($selectedExpense)->actual()->create(['amount' => '10.00']);
    ExpenseLine::factory()->for($otherExpense)->actual()->create(['amount' => '20.00']);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'suppliers')
        ->assertSet('report.sources', fn (array $sources): bool => count($sources) === 2)
        ->set('supplierId', $selectedSupplier->id)
        ->assertHasNoErrors()
        ->assertSet('report.sources', fn (array $sources): bool => count($sources) === 1 && $sources[0]['label'] === 'Inclusa')
        ->assertSee('Inclusa')
        ->assertDontSee('Esclusa')
        ->assertSee('Fornitore: Fornitore selezionato')
        ->assertSee('Azzera Filtri');
});

it('switches directly while preserving compatible references and filters', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($supplier)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    $budget = reportingUiBudget($company, $exercise, $expense);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_actual')
        ->set('budgetId', $budget->id)
        ->set('actualReference', 'current')
        ->set('supplierId', $supplier->id)
        ->call('switchReport', 'budget_current_allocation')
        ->assertSet('kind', 'budget_current_allocation')
        ->assertSet('exerciseId', $exercise->id)
        ->assertSet('budgetId', $budget->id)
        ->assertSet('actualReference', null)
        ->assertSet('supplierId', $supplier->id)
        ->assertSet('definition.kind', 'budget_current_allocation')
        ->assertSet('definition.filters.supplier_id', $supplier->id)
        ->assertSet('report', fn (?array $report): bool => $report !== null)
        ->assertDontSee('Scegli il Report');
});

it('preserves annual references when switching to budget actual', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    $budget = reportingUiBudget($company, $exercise, $expense);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->set('budgetId', $budget->id)
        ->set('actualReference', 'current')
        ->call('switchReport', 'budget_actual')
        ->assertSet('exerciseId', $exercise->id)
        ->assertSet('budgetId', $budget->id)
        ->assertSet('actualReference', 'current')
        ->assertSet('definition.kind', 'budget_actual')
        ->assertSet('report', fn (?array $report): bool => $report !== null);
});

it('drops budget and actual but keeps the supplier filter when switching to suppliers', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $supplier = Supplier::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($supplier)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    $budget = reportingUiBudget($company, $exercise, $expense);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_actual')
        ->set('budgetId', $budget->id)
        ->set('actualReference', 'current')
        ->set('supplierId', $supplier->id)
        ->call('switchReport', 'suppliers')
        ->assertSet('exerciseId', $exercise->id)
        ->assertSet('budgetId', null)
        ->assertSet('actualReference', null)
        ->assertSet('supplierId', $supplier->id)
        ->assertSet('definition.kind', 'suppliers')
        ->assertSet('definition.filters.supplier_id', $supplier->id)
        ->assertSet('report', fn (?array $report): bool => $report !== null);
});

it('drops the contract date interval but keeps general filters when switching to projects', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $supplier = Supplier::factory()->for($company)->create();
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'contracts')
        ->set('supplierId', $supplier->id)
        ->set('dateFrom', '2026-01-01 00:00:00')
        ->set('dateTo', '2026-12-31 00:00:00')
        ->assertSee('Intervallo: 2026-01-01 – 2026-12-31')
        ->call('switchReport', 'projects')
        ->assertSet('exerciseId', $exercise->id)
        ->assertSet('supplierId', $supplier->id)
        ->assertSet('dateFrom', null)
        ->assertSet('dateTo', null)
        ->assertSet('definition.kind', 'projects')
        ->assertSet('definition.filters.supplier_id', $supplier->id);
});

it('keeps one budget and requests the second when switching to budget versions', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    $budget = reportingUiBudget($company, $exercise, $expense);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_current_allocation')
        ->set('budgetId', $budget->id)
        ->call('switchReport', 'budget_versions')
        ->assertSet('exerciseId', $exercise->id)
        ->assertSet('budgetId', $budget->id)
        ->assertSet('secondBudgetId', null)
        ->assertSet('report', null)
        ->assertSet('definition', null)
        ->assertSee('Completa i Riferimenti')
        ->assertSee('Budget Finale');
});

it('does not generate a new contracts report from a partial date interval', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'contracts')
        ->assertSet('definition.kind', 'contracts')
        ->set('dateFrom', '2026-01-01')
        ->assertSet('report', null)
        ->assertSet('definition', null)
        ->assertSee('Completa entrambe le date dell’intervallo');
});

it('declares closing as the baseline for closed current knowledge', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    closeExerciseFixture($exercise, $viewer);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->set('actualReference', 'current_knowledge')
        ->assertHasNoErrors()
        ->assertSet('definition.initial_reference.type', 'closing')
        ->assertSet('definition.final_reference.type', 'current_knowledge')
        ->assertSee('Snapshot di Chiusura');
});

it('removes the previous result when a newly selected reference is unavailable', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->set('actualReference', 'current')
        ->assertSet('report', fn (?array $report): bool => $report !== null)
        ->set('actualReference', 'closing')
        ->assertHasErrors('actualReference')
        ->assertSet('report', null)
        ->assertSet('definition', null)
        ->assertSee('La Snapshot di Chiusura richiesta non esiste.');
});

it('renders canonical classification and structured detail without raw json or false budget zero', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Variazione visibile']);
    ExpenseLine::factory()->for($expense)->create(['amount' => '120.00', 'note' => 'Riga leggibile']);
    $budget = reportingUiBudget($company, $exercise, $expense, '100.00');
    $noBudgetExercise = Exercise::factory()->for($company)->create(['year' => 2027]);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_current_allocation')
        ->set('budgetId', $budget->id)
        ->assertHasNoErrors()
        ->assertSee('Modificato')
        ->assertSee('Senza Effettivi')
        ->assertSee('Variazione non sufficientemente spiegata')
        ->assertSee('Riga leggibile')
        ->assertDontSeeHtml('<pre>');

    Livewire::test(Reports::class)
        ->set('exerciseId', $noBudgetExercise->id)
        ->set('kind', 'annual_executive')
        ->set('actualReference', 'current')
        ->assertSee('Budget Approvato Corrente')
        ->assertSee('Non disponibile');
});

it('renders contract drill-down across the full row without technical persistence fields', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'title' => 'Contratto leggibile',
        'next_expiry_date' => '2028-02-20',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '1138.50',
        'cycle' => 'annual',
        'attribution_mode' => 'cycle_start',
        'valid_from' => '2026-02-20',
        'created_by_id' => $viewer->id,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2026-02-20',
        'expiry_anchor_date' => '2028-02-20',
        'renewal_duration_months' => 24,
        'notice_days' => 30,
        'created_by_id' => $viewer->id,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => '2026-02-20',
        'state_change_date' => '2026-02-20',
        'reason' => 'Avvio del servizio',
        'created_by_id' => $viewer->id,
    ]);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->set('actualReference', 'current')
        ->assertHasNoErrors()
        ->assertSee('Dettaglio Contratto')
        ->assertSee('Condizioni Economiche')
        ->assertSee('Configurazioni di Rinnovo')
        ->assertSee('Eventi Contrattuali')
        ->assertSee('Annuale')
        ->assertSee('Inizio Ciclo')
        ->assertSee('24 mesi')
        ->assertSee('Avvio del servizio')
        ->assertSeeHtml('class="mp2-report-detail-row"')
        ->assertSeeHtml('colspan="9"')
        ->assertDontSee('Company id')
        ->assertDontSee('Created by id')
        ->assertDontSee('Created at')
        ->assertDontSee('Updated at')
        ->assertDontSee('contract:'.$contract->id)
        ->assertDontSee('2026-02-20T00:00:00.000000Z');
});

it('shows only budget references and their variation in budget versions KPIs', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $initialBudget = reportingUiBudget($company, $exercise, $expense, '100.00');
    $finalBudget = reportingUiBudget($company, $exercise, $expense, '115.00', 2);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_versions')
        ->set('budgetId', $initialBudget->id)
        ->set('secondBudgetId', $finalBudget->id)
        ->assertHasNoErrors()
        ->assertSee('Budget v1')
        ->assertSee('Budget v2')
        ->assertSee('Variazione fra Budget')
        ->assertDontSee('Effettivo del Riferimento')
        ->assertDontSee('Scostamento Operativo del Riferimento');
});

it('names the selected budget actual references and variance in its KPIs', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '90.00']);
    $budget = reportingUiBudget($company, $exercise, $expense, '100.00');
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_actual')
        ->set('budgetId', $budget->id)
        ->set('actualReference', 'current')
        ->assertHasNoErrors()
        ->assertSee('Budget v1')
        ->assertSee('Effettivo Corrente')
        ->assertSee('Varianza Budget vs Actual');
});

it('names budget allocation references and variation in current allocation KPIs', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '115.00']);
    $budget = reportingUiBudget($company, $exercise, $expense, '100.00');
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'budget_current_allocation')
        ->set('budgetId', $budget->id)
        ->assertHasNoErrors()
        ->assertSee('Budget v1')
        ->assertSee('Situazione Corrente')
        ->assertSee('Variazione Allocato vs Budget')
        ->assertSet('report.comparison_totals.initial', '100.00')
        ->assertSet('report.comparison_totals.final', '115.00')
        ->assertSet('report.comparison_totals.delta', '15.00')
        ->assertSet('report.charts.0.data.datasets.0.data', [100.0, 115.0]);
});

it('names the selected measure in exercise comparison KPIs', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $initialExercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $finalExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    reportingUiContext($company, $viewer);

    Livewire::test(Reports::class)
        ->set('exerciseId', $initialExercise->id)
        ->set('kind', 'exercises')
        ->set('comparisonExerciseId', $finalExercise->id)
        ->set('exerciseMeasure', 'current')
        ->assertHasNoErrors()
        ->assertSee('Misura: Situazione Corrente')
        ->assertSee('Esercizio 2025')
        ->assertSee('Esercizio 2026')
        ->assertSee('Delta Complessivo');
});

it('denies the report page without visualizza', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    reportingUiContext($company, $user);

    expect(Reports::canAccess())->toBeFalse();
});
