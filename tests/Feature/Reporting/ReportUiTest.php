<?php

use App\Filament\Pages\Reports;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
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

function reportingUiBudget(Company $company, Exercise $exercise, Expense $expense, string $amount = '100.00'): BudgetSnapshot
{
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'version' => 1,
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
        ->assertSee('Scegli il report')
        ->assertSee('Vista annuale esecutiva')
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
        ->assertSee('Completa i riferimenti')
        ->set('actualReference', 'current')
        ->assertHasNoErrors()
        ->assertSet('definition.actual_reference', 'current')
        ->assertSee('Vista annuale esecutiva')
        ->assertSee('Effettivo Corrente')
        ->assertSee('Servizio UI')
        ->assertSee('Esporta PDF')
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
        ->assertSee('Azzera filtri');
});

it('removes incompatible hidden references when changing report family', function (): void {
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
        ->call('changeReport')
        ->assertSet('kind', null)
        ->assertSet('budgetId', null)
        ->assertSet('actualReference', null)
        ->assertSet('supplierId', null)
        ->call('selectReport', 'projects')
        ->assertSet('kind', 'projects')
        ->assertSet('definition.kind', 'projects')
        ->assertSet('definition.filters', []);
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
        ->assertSee('Budget approvato corrente')
        ->assertSee('Non disponibile');
});

it('denies the report page without visualizza', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    reportingUiContext($company, $user);

    expect(Reports::canAccess())->toBeFalse();
});
