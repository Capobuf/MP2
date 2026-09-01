<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Domain\Proposals\ProposalActionType;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('links Proposal and Budget to their tenant-scoped immutable timeline', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();

    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $actor,
            'permissions' => $capability,
        ]);
    }

    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create([
        'type' => 'estimate',
        'amount' => '4.00',
    ]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    app(PlanExpense::class)->execute(
        $actor,
        $proposal,
        $proposal->items->sole(),
        ProposalActionType::SetExpenseEstimates,
        ['estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => $line->id,
            'amount' => '6.00',
            'note' => null,
            'annulled' => false,
        ]]],
        null,
        (string) Str::uuid(),
        0,
    );
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid(), ['external_subject' => 'Direzione', 'external_venue' => 'Verbale approvazione']);
    $proposalEvents = AuditEvent::query()
        ->where('company_id', $company->id)
        ->where(fn ($query) => $query
            ->where(fn ($subject) => $subject->where('subject_type', $proposal::class)->where('subject_id', $proposal->id))
            ->orWhere('new_value->proposal_id', $proposal->id))
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->get();
    $budgetEvents = AuditEvent::query()
        ->where('company_id', $company->id)
        ->where(fn ($query) => $query
            ->where(fn ($subject) => $subject->where('subject_type', $budget::class)->where('subject_id', $budget->id))
            ->orWhere('new_value->budget_id', $budget->id))
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->get();
    $budgetCreated = $budgetEvents->first(fn (AuditEvent $event): bool => $event->eventType()->value === 'budget_created');

    $this->actingAs($actor);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertActionHasUrl('timeline', CompanyAudit::getUrl([
            'tenant' => $company,
            'proposal' => $proposal->id,
        ]));
    Livewire::test(ViewBudget::class, ['record' => $budget->getRouteKey()])
        ->assertActionHasUrl('timeline', CompanyAudit::getUrl([
            'tenant' => $company,
            'budget' => $budget->id,
        ]));

    Livewire::withQueryParams(['proposal' => $proposal->id])
        ->test(CompanyAudit::class)
        ->assertCanSeeTableRecords($proposalEvents, inOrder: true)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
    Livewire::withQueryParams(['budget' => $budget->id])
        ->test(CompanyAudit::class)
        ->assertCanSeeTableRecords($budgetEvents, inOrder: true)
        ->assertSee('Budget Creato')
        ->mountTableAction('details', record: $budgetCreated)
        ->assertSchemaComponentExists('detail_new')
        ->assertSchemaComponentExists('detail_reference')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});
