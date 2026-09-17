<?php

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalReadinessState;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('recalculates readiness immediately without an empty modal and audits each click', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_PROPOSALS]]);
    $expense = Expense::factory()->forExercise($proposal->exercise)->create();
    $this->actingAs($user);
    Filament::setTenant($proposal->company->tenantCompany);

    $page = Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->mountAction('reviewReadiness')
        ->assertHasNoActionErrors()
        ->assertActionNotMounted()
        ->assertNotified('Verifiche Ricalcolate');

    expect($proposal->fresh()->revision)->toBe(1)
        ->and($proposal->items()->where('expense_id', $expense->id)->sole()->readiness_state)->toBe(ProposalReadinessState::ToReview);

    $page->mountAction('reviewReadiness')->assertHasNoActionErrors()->assertActionNotMounted();

    $events = AuditEvent::query()->where('subject_id', $proposal->id)->where('event_type', AuditEventType::ProposalReadinessReviewed)->get();
    expect($proposal->fresh()->revision)->toBe(2)
        ->and($events)->toHaveCount(2)
        ->and($events->pluck('operation_id')->unique())->toHaveCount(2);
    foreach ($events as $event) {
        expect(Str::isUuid($event->operation_id))->toBeTrue();
    }
});

it('shows all canonical readiness labels, impacts and S7 resolution controls', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => $capability]);
    }
    foreach (['aligned', 'to_review', 'to_realign', 'inconsistent'] as $state) {
        ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'expense', 'readiness_state' => $state]);
    }
    $this->actingAs($user);
    Filament::setTenant(($proposal->company)->tenantCompany);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists('reviewReadiness')
        ->assertActionVisible(TestAction::make('reloadReality')->table($proposal->items()->where('readiness_state', 'to_realign')->sole()))
        ->assertActionVisible(TestAction::make('keepProposal')->table($proposal->items()->where('readiness_state', 'to_realign')->sole()))
        ->assertActionVisible(TestAction::make('manualRealignment')->table($proposal->items()->where('readiness_state', 'to_realign')->sole()))
        ->assertActionVisible(TestAction::make('acknowledgeSource')->table($proposal->items()->where('readiness_state', 'to_review')->sole()))
        ->assertSee('Allineato')
        ->assertSee('Da Prendere in Visione')
        ->assertSee('Da Riallineare')
        ->assertSee('Incoerente')
        ->assertSee('Esercizi Interessati');
});
