<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Domain\Proposals\ProposalReadiness;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable as Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-21 10:00:00 Europe/Rome');
    $this->proposal = Proposal::factory()->create();
    $this->proposal->exercise->update(['year' => 2027]);
    $this->user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $permission) {
        grantTestPermissions(['company_id' => $this->proposal->company_id, 'user' => $this->user, 'permissions' => $permission]);
    }
    $this->actingAs($this->user);
    Filament::setTenant($this->proposal->company->tenantCompany);
    $this->supplier = Supplier::factory()->create(['company_id' => $this->proposal->company_id]);
});

afterEach(fn () => Carbon::setTestNow());

it('creates a complete contract through the form and approves its calculated estimates', function (string $cycle, string $attribution, string $total): void {
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->callAction('createPlannedContract', data: [
            'title' => 'Assistenza', 'supplier_id' => $this->supplier->id,
            'contractual_start_date' => '01/01/2027', 'amount' => '120,50',
            'cycle' => $cycle, 'attribution_mode' => $attribution,
        ])->assertHasNoActionErrors();

    $item = $this->proposal->items()->sole();
    expect($item->result['planned_conditions'][0]['amount'])->toBe('120.50')
        ->and($item->result['planned_conditions'][0]['valid_from'])->toBe('2027-01-01')
        ->and($item->actions()->count())->toBe(2)
        ->and(Contract::query()->count())->toBe(0)
        ->and(app(ProposalReadiness::class)->assessProposal($this->proposal->fresh())['ready'])->toBeTrue();

    app(ApproveProposal::class)->execute($this->user, $this->proposal->fresh(), (string) Str::uuid());
    expect(ContractCondition::query()->sole()->amount)->toBe('120.50');
    if ($total === '0.00') {
        expect(Expense::query()->count())->toBe(0);
    } else {
        expect(Expense::query()->sole()->lines()->sole()->amount)->toBe($total);
    }
})->with([
    ['monthly', 'cycle_start', '1446.00'], ['monthly', 'cycle_end', '1325.50'],
    ['quarterly', 'cycle_start', '482.00'], ['quarterly', 'cycle_end', '361.50'],
    ['semiannual', 'cycle_start', '241.00'], ['semiannual', 'cycle_end', '120.50'],
    ['annual', 'cycle_start', '120.50'], ['annual', 'cycle_end', '0.00'],
]);

it('requires a first amount and rejects negative amounts without creating a partial contract', function (mixed $amount): void {
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->callAction('createPlannedContract', data: [
            'title' => 'Assistenza', 'supplier_id' => $this->supplier->id,
            'contractual_start_date' => '01/01/2027', 'amount' => $amount,
            'cycle' => 'annual', 'attribution_mode' => 'cycle_start',
        ])->assertHasActionErrors(['amount']);
    expect($this->proposal->items()->count())->toBe(0)->and($this->proposal->fresh()->revision)->toBe(0);
})->with([null, '-1,00']);

it('rolls back invalid first conditions and records a complete creation only once', function (): void {
    $payload = ['title' => 'Assistenza', 'supplier_id' => $this->supplier->id, 'contractual_start_date' => '2027-01-01', 'exercise_id' => $this->proposal->exercise_id];
    $condition = ['amount' => '10.00', 'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'valid_to' => '2026-12-31'];
    $operation = (string) Str::uuid();
    $action = app(PlanContract::class);
    expect(fn () => $action->createWithCondition($this->user, $this->proposal, $payload, $condition, $operation, 0))->toThrow(ValidationException::class);
    expect($this->proposal->items()->count())->toBe(0)->and($this->proposal->actions()->count())->toBe(0)->and($this->proposal->fresh()->revision)->toBe(0);
    $condition['valid_to'] = null;
    $created = $action->createWithCondition($this->user, $this->proposal->fresh(), $payload, $condition, $operation, 0);
    $retry = $action->createWithCondition($this->user, $this->proposal->fresh(), $payload, $condition, $operation, 0);
    expect($retry->id)->toBe($created->id)->and($this->proposal->actions()->count())->toBe(2)->and($created->item->result['planned_conditions'])->toHaveCount(1);
});

it('shows the precise missing condition message on an incomplete existing draft', function (): void {
    app(PlanContract::class)->create($this->user, $this->proposal, ['title' => 'Incompleto', 'supplier_id' => $this->supplier->id, 'contractual_start_date' => '2027-01-01', 'exercise_id' => $this->proposal->exercise_id], (string) Str::uuid(), 0);
    $review = app(ProposalReadiness::class)->assessProposal($this->proposal->fresh());
    expect($review['blocks'][0]['message'])->toBe('Un nuovo Contratto richiede almeno una condizione economica applicabile.');
    $item = $this->proposal->items()->sole();
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->callAction('addContractCondition', data: ['item_id' => $item->id, 'amount' => '0,00', 'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'valid_from' => '01/01/2027'])
        ->assertHasNoActionErrors();
    expect(app(ProposalReadiness::class)->assessProposal($this->proposal->fresh())['ready'])->toBeTrue();
});

it('selects a live condition and displays the economic preview before confirming the change', function (): void {
    $contract = Contract::factory()->create(['company_id' => $this->proposal->company_id, 'contractual_start_date' => '2026-01-15', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['cycle' => 'monthly', 'valid_from' => '2026-01-15', 'amount' => '100.00', 'valid_to' => null]);
    $exercise = Exercise::factory()->create(['company_id' => $this->proposal->company_id, 'year' => 2026]);
    $proposal = app(InitializeProposal::class)->execute($this->user, $this->proposal->company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    $page = Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->mountAction('changeContractEconomics')
        ->fillForm(['item_id' => $item->id, 'condition_id' => $condition->id, 'amount' => '150,25', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_end', 'requested_date' => '22/08/2026'])
        ->assertSchemaComponentExists('economic_preview', checkComponentUsing: function (Placeholder $component): bool {
            expect($component->getContent()->render())->toContain('15/09/2026', 'Data minima richiedibile', 'Termini Economici', 'Impatto sugli Esercizi Aperti');

            return true;
        });
    $page->fillForm(['confirmed_effective_date' => '01/09/2026'])->callMountedAction()->assertHasErrors(['confirmed_effective_date'])->assertNotified('Operazione non completata');
    expect($proposal->actions()->count())->toBe(0);
    $page->fillForm(['confirmed_effective_date' => '15/09/2026'])->callMountedAction()->assertHasNoActionErrors();
    expect($item->fresh()->result['planned_condition_changes'][0]['amount'])->toBe('150.25')->and($condition->fresh()->amount)->toBe('100.00');
});

it('creates a planned project and expense then edits the estimates without live effects', function (): void {
    $page = Livewire::test(ViewProposal::class, ['record' => $this->proposal->id]);
    $page->callAction('createPlannedProject', data: ['title' => 'Migrazione', 'initial_effective_date' => '01/01/2027'])->assertHasNoActionErrors();
    $project = $this->proposal->items()->where('source_type', 'project')->sole();
    $line = ['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '900719925474099,91', 'annulled' => false];
    $page->callAction('createPlannedExpense', data: ['description' => 'Licenze', 'project_reference' => 'item:'.$project->proposal_item_id, 'estimate_lines' => [$line]])->assertHasNoActionErrors();
    $expense = $this->proposal->items()->where('source_type', 'expense')->sole();
    expect($expense->result['estimate_lines'][0]['amount'])->toBe('900719925474099.91');
    $line['amount'] = '100,25';
    $page->callAction('planExpenseEstimates', data: ['item_id' => $expense->id, 'estimate_lines' => [$line]])->assertHasNoActionErrors();
    expect($expense->fresh()->result['estimate_lines'][0]['amount'])->toBe('100.25')
        ->and(Project::query()->count())->toBe(0)->and(Expense::query()->count())->toBe(0)
        ->and(app(ProposalReadiness::class)->assessProposal($this->proposal->fresh())['ready'])->toBeTrue();
});

it('applies the initial expiry and renewal to both the proposal and the approved contract', function (bool $automatic, string $total): void {
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->callAction('createPlannedContract', data: [
            'title' => 'Assistenza', 'supplier_id' => $this->supplier->id,
            'contractual_start_date' => '01/01/2027', 'next_expiry_date' => '30/06/2027',
            'automatic_renewal' => $automatic, 'renewal_duration_months' => $automatic ? 6 : null,
            'amount' => '100,00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start',
        ])->assertHasNoActionErrors();
    $review = app(ProposalReadiness::class)->assessProposal($this->proposal->fresh());
    expect($review['ready'])->toBeTrue()->and($review['impacts'][0]['allocation_after'])->toBe($total);
    app(ApproveProposal::class)->execute($this->user, $this->proposal->fresh(), (string) Str::uuid());
    $contract = Contract::query()->sole();
    expect($contract->renewalConfigurations()->sole()->automatic_renewal)->toBe($automatic)
        ->and($contract->stateAtDate('2027-07-01')->value)->toBe($automatic ? 'active' : 'cessated')
        ->and(Expense::query()->sole()->lines()->sole()->amount)->toBe($total);
})->with([[false, '600.00'], [true, '1200.00']]);

it('requires the renewal duration when an initial expiry renews automatically', function (): void {
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->callAction('createPlannedContract', data: [
            'title' => 'Assistenza', 'supplier_id' => $this->supplier->id,
            'contractual_start_date' => '01/01/2027', 'next_expiry_date' => '30/06/2027',
            'automatic_renewal' => true, 'amount' => '100,00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start',
        ])->assertHasErrors(['renewal_duration_months'])->assertNotified('Operazione non completata');
    expect($this->proposal->items()->count())->toBe(0);
});
