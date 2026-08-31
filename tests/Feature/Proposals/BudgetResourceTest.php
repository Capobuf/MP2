<?php

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('lists and views only immutable Budgets belonging to the active tenant', function (): void {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $viewer,
        'permissions' => TestPermissions::VIEW,
    ]);
    $exercise = Exercise::factory()->for($company)->create();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'total_approved_allocation' => '125.50',
        'affected_exercises' => [2026],
    ]);
    $row = BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'label' => 'Licenze approvate',
        'approved_estimates' => '125.50',
        'approved_allocation' => '125.50',
        'detail' => ['identity' => ['source_type' => 'expense'], 'expense' => ['description' => 'Licenze approvate', 'approved_estimate_total' => '125.50'], 'approved_actions' => [], 'relations' => [], 'approval_event_sequences' => [0, 1]],
    ]);
    BudgetEvidence::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'external_subject' => 'Direzione',
        'reason' => 'Verbale approvato',
    ]);
    $otherExercise = Exercise::factory()->for($otherCompany)->create();
    $otherProposal = Proposal::factory()->for($otherCompany)->for($otherExercise)->create();
    $hiddenBudget = BudgetSnapshot::factory()->for($otherProposal)->create();

    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ListBudgets::class)
        ->assertCanSeeTableRecords([$budget])
        ->assertCanNotSeeTableRecords([$hiddenBudget])
        ->assertTableActionDoesNotExist('edit', record: $budget)
        ->assertTableActionDoesNotExist('delete', record: $budget);

    Livewire::test(ViewBudget::class, ['record' => $budget->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Budget immutabile')
        ->assertSee('Budget '.$exercise->year.' · v1')
        ->assertSee('Versione approvata')
        ->assertSee('sorgente inclusa')
        ->assertSee('Dettaglio Spesa')
        ->assertSee('Azioni e motivazioni approvate')
        ->assertSee('Riferimenti e tracciabilità tecnica')
        ->assertSee($row->label)
        ->assertSee('Direzione')
        ->assertSee('Verbale approvato')
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete')
        ->assertDontSee('Effettivo')
        ->assertDontSee('Forecast')
        ->assertDontSee('Closing')
        ->assertDontSee('Residuo')
        ->assertDontSee('approved_estimate_total');

    $this->get(BudgetResource::getUrl('view', ['record' => $hiddenBudget], tenant: $company))
        ->assertNotFound();
});
