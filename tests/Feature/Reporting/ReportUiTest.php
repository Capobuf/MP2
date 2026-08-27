<?php

use App\Filament\Pages\Reports;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('starts without implicit references and generates the annual report', function (): void {
    $company = Company::factory()->create(['name' => 'UI Company']);
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Servizio UI']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '12.00']);
    $this->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(Reports::class)
        ->assertSuccessful()
        ->assertSet('exerciseId', null)
        ->assertSet('kind', null)
        ->assertSee('Nessun Budget o tipo di Effettivo viene scelto automaticamente')
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->set('actualReference', 'current')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('Vista annuale esecutiva')
        ->assertSee('Servizio UI')
        ->assertSee('Esporta PDF');
});

it('denies the report page without visualizza', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    expect(Reports::canAccess())->toBeFalse();
});

it('offers tenant filters and applies the selected supplier explicitly', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    $selectedSupplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore selezionato']);
    $otherSupplier = Supplier::factory()->for($company)->create(['legal_name' => 'Fornitore escluso']);
    $selectedExpense = Expense::factory()->forExercise($exercise)->for($selectedSupplier)->create(['description' => 'Inclusa']);
    $otherExpense = Expense::factory()->forExercise($exercise)->for($otherSupplier)->create(['description' => 'Esclusa']);
    ExpenseLine::factory()->for($selectedExpense)->actual()->create(['amount' => '10.00']);
    ExpenseLine::factory()->for($otherExpense)->actual()->create(['amount' => '20.00']);
    $this->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(Reports::class)
        ->assertSee('Filtri applicabili')
        ->assertSee('Fornitore selezionato')
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'suppliers')
        ->set('supplierId', $selectedSupplier->id)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('Inclusa')
        ->assertSet('report.sources', fn (array $sources): bool => count($sources) === 1 && $sources[0]['label'] === 'Inclusa')
        ->assertSee('Fornitore: Fornitore selezionato');
});

it('declares closing as the baseline for closed current knowledge', function (): void {
    $company = Company::factory()->create();
    $viewer = s11ReportingViewer($company);
    $exercise = Exercise::factory()->for($company)->create();
    closeExerciseFixture($exercise, $viewer);
    $this->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(Reports::class)
        ->set('exerciseId', $exercise->id)
        ->set('kind', 'annual_executive')
        ->set('actualReference', 'current_knowledge')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSet('definition.initial_reference.type', 'closing')
        ->assertSet('definition.final_reference.type', 'current_knowledge')
        ->assertSee('Snapshot di Chiusura');
});
