<?php

use App\Domain\Proposals\BudgetPayloadGuard;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalPurpose;
use App\Domain\Proposals\ProposalRelationPlan;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('rejects every non-plan baseline family recursively', function (string $key): void {
    expect(fn () => BudgetPayloadGuard::assertPlanOnly([
        'permitted' => ['nested' => [$key => '1.00']],
    ]))->toThrow(ValidationException::class);
})->with([
    'Actual' => 'actual_total',
    'Effettivo' => 'effettivo_corrente',
    'Forecast' => 'forecast_amount',
    'Closing' => 'closing_total',
    'Residuo' => 'residual_amount',
    'Varianza' => 'variance_amount',
    'Scostamento' => 'scostamento_budget',
    'Saving' => 'saving_amount',
    'Sovraspesa' => 'overspend_amount',
    'Correzione tardiva' => 'late_correction',
]);

it('does not represent later-slice actions or non-canonical source replacement', function (): void {
    foreach ([
        'realign_source',
        'keep_proposal',
        'carryover',
        'reprogramming',
        'closing',
        'forecast',
        'revision',
        'replace_project_contract',
    ] as $excluded) {
        expect(ProposalActionType::tryFrom($excluded))->toBeNull();
    }

    expect(ProposalPurpose::cases())->toHaveCount(2)
        ->and(ProposalPurpose::cases())->toContain(ProposalPurpose::InitialBudget, ProposalPurpose::Revision);
});

it('requires exact stable relation identities and never fuzzy labels', function (): void {
    $proposal = Proposal::factory()->create();

    expect(fn () => ProposalRelationPlan::validate($proposal, [
        'project_origin_key' => 'Progetto simile',
        'contract_origin_key' => 'Contratto simile',
    ]))->toThrow(ValidationException::class);
});

it('rejects physical deletion of the full Proposal and Budget aggregate', function (): void {
    $proposal = Proposal::factory()->create();
    $item = ProposalItem::factory()->for($proposal)->create([
        'company_id' => $proposal->company_id,
    ]);
    $action = ProposalAction::factory()->for($proposal)->create([
        'company_id' => $proposal->company_id,
        'proposal_item_id' => $item->id,
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create();
    $row = BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $proposal->company_id,
    ]);
    $evidence = BudgetEvidence::factory()->for($budget, 'budget')->create([
        'company_id' => $proposal->company_id,
    ]);

    foreach ([$proposal, $item, $action, $budget, $row, $evidence] as $record) {
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }
});

it('enforces one parallel Draft per company and Exercise at the database boundary', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    Proposal::factory()->for($company)->for($exercise)->create();

    expect(fn () => Proposal::factory()->for($company)->for($exercise)->create())
        ->toThrow(QueryException::class);
});

it('does not expose carryover replacement closing forecast delete or export controls', function (): void {
    $viewer = User::factory()->create();
    $proposal = Proposal::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions([
            'company_id' => $proposal->company_id,
            'user' => $viewer,
            'permissions' => $capability,
        ]);
    }
    $budget = BudgetSnapshot::factory()->for($proposal)->create();
    $this->actingAs($viewer);
    Filament::setTenant(($proposal->company)->tenantCompany);

    $proposalPage = Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()]);
    foreach (['carryover', 'reprogramming', 'replace', 'closing', 'forecast', 'delete', 'export'] as $action) {
        $proposalPage->assertActionDoesNotExist($action);
    }

    Livewire::test(ViewBudget::class, ['record' => $budget->getRouteKey()])
        ->assertActionDoesNotExist('createRevision')
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('export');

    expect(BudgetResource::canCreate())->toBeFalse()
        ->and(BudgetResource::getPages())->not->toHaveKey('create')
        ->and(BudgetResource::getPages())->not->toHaveKey('edit');
});
