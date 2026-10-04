<?php

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Filament\Resources\Proposals\ProposalResource;
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
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
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
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'label' => 'Decisione futura',
        'approved_estimates' => '0.00',
        'approved_allocation' => '0.00',
        'detail' => [
            'identity' => ['source_type' => 'expense'],
            'expense' => [
                'description' => 'Decisione futura', 'exercise_id' => 999, 'exercise_year' => 2027,
                'approved_estimate_total' => '0.00', 'active_estimate_lines' => [],
            ],
            'approved_actions' => [['sequence' => 1, 'type' => 'create_expense', 'payload' => ['amount' => '300.00']]],
            'relations' => [],
            'approval_event_sequences' => [2],
        ],
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
        ->assertSee('Versioni approvate e immutabili')
        ->assertActionHidden('prepareBudget')
        ->assertTableActionDoesNotExist('edit', record: $budget)
        ->assertTableActionDoesNotExist('delete', record: $budget);

    Livewire::test(ViewBudget::class, ['record' => $budget->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Budget Immutabile')
        ->assertSee('Budget '.$exercise->year.' · v1')
        ->assertSee('Versione Approvata')
        ->assertSee('Elementi del Budget')
        ->assertDontSee('Sorgenti Materializzate')
        ->assertSee('Sorgenti Incluse')
        ->assertSee('Dettaglio Spesa')
        ->assertSee('Spesa dell’Esercizio 2027.')
        ->assertSee('Contributo al Budget 2026:')
        ->assertSee('0,00')
        ->assertSee('Le componenti qui mostrate appartengono al Budget selezionato; la decisione resta nelle azioni approvate.')
        ->assertSee('Azioni e Motivazioni Approvate')
        ->assertSee('Riferimenti e Tracciabilità Tecnica')
        ->assertSee($row->label)
        ->assertSee('Direzione')
        ->assertSee('Verbale approvato')
        ->assertSeeHtml('href="'.ProposalResource::getUrl('view', ['record' => $proposal], tenant: $company->tenantCompany).'"')
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
