<?php

use App\Actions\Operations\ChangeContractCondition;
use App\Actions\Operations\CreateContract;
use App\Actions\Operations\SaveContractEdits;
use App\Domain\Company\AuditEventType;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-28 10:00:00 Europe/Rome');
    $this->actor = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantTestPermissions(['company_id' => $this->company->id, 'user' => $this->actor, 'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_OPERATIONS]]);
    $this->previousYear = Exercise::factory()->for($this->company)->create(['year' => 2025]);
    $this->exercise = Exercise::factory()->for($this->company)->create(['year' => 2026]);
    $this->contract = app(CreateContract::class)->execute($this->actor, $this->company, [
        'title' => 'Canone annuale', 'supplier_id' => Supplier::factory()->for($this->company)->create()->id,
        'contractual_start_date' => '2025-01-01', 'automatic_renewal' => false,
        'conditions' => [['amount' => '15.00', 'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'valid_from' => '2025-01-01']],
    ], (string) Str::uuid());
    $this->condition = $this->contract->conditions()->sole();
    $this->actingAs($this->actor);
    Filament::setTenant($this->company->tenantCompany);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function successionInput(array $overrides = []): array
{
    return array_replace([
        'succession' => true, 'requested_date' => '2026-01-01', 'valid_to' => null,
        'amount' => '30.50', 'cycle' => 'annual', 'attribution_mode' => 'cycle_start',
        'reason' => 'Nuovo canone già applicato dal rinnovo 2026',
    ], $overrides);
}

it('adds a row from edit and preserves the closed previous year budget actuals and contract expiry', function () {
    $closing = closeExerciseFixture($this->previousYear, $this->actor);
    $closingBefore = $closing->fresh()->getAttributes();
    $budget = BudgetSourceRow::factory()->create([
        'budget_snapshot_id' => BudgetSnapshot::factory()->for(Proposal::factory()->for($this->company)->for($this->exercise)->state(['status' => 'approved']))->create()->id,
        'company_id' => $this->company->id, 'source_type' => 'contract', 'origin_id' => $this->contract->id, 'origin_key' => $this->contract->originKey(),
    ]);
    $budgetBefore = $budget->fresh()->getAttributes();
    $actual = ExpenseLine::factory()->actual()->for(Expense::factory()->forExercise($this->exercise)->create([
        'contract_id' => $this->contract->id, 'supplier_id' => $this->contract->supplier_id,
    ]))->create(['amount' => '21.00']);
    $actualBefore = $actual->fresh()->getAttributes();
    $draft = Proposal::factory()->for($this->company)->for($this->exercise)->create(['created_by_id' => $this->actor->id]);
    $item = ProposalItem::factory()->for($draft)->create([
        'company_id' => $this->company->id, 'source_type' => 'contract', 'contract_id' => $this->contract->id,
        'baseline_revision' => $this->contract->revision, 'baseline_fingerprint' => hash('sha256', 'baseline'),
    ]);

    $component = Livewire::test(EditContract::class, ['record' => $this->contract->getRouteKey()]);
    $previousKey = array_key_first($component->get('data.conditions'));
    $component->callFormComponentAction('conditions', 'add');
    $newKey = array_key_last($component->get('data.conditions'));
    expect($newKey)->not->toBe($previousKey);
    $component->set("data.conditions.{$newKey}.amount", '30.50')
        ->set("data.conditions.{$newKey}.cycle", 'annual')
        ->set("data.conditions.{$newKey}.attribution_mode", 'cycle_start')
        ->call('save')->assertHasFormErrors(["conditions.{$previousKey}.valid_to"]);
    $component->set("data.conditions.{$previousKey}.valid_to", '31/12/2025');
    expect($component->get("data.conditions.{$newKey}.valid_from"))->toBe('01/01/2026');
    $component->call('save')->assertHasNoFormErrors()->assertActionMounted('interpretChanges')
        ->fillForm(['reason' => 'Nuovo canone dal 2026'])->callMountedAction()
        ->assertHasNoActionErrors()->assertActionMounted('confirmChanges')
        ->assertMountedActionModalSee('31/12/2025')->assertMountedActionModalSee('01/01/2026')
        ->assertMountedActionModalSee('Impatto 2026')->assertMountedActionModalSee('La scadenza del Contratto resta invariata.');
    expect($this->contract->conditions()->count())->toBe(1);
    $component->fillForm(['confirmed' => false])->callMountedAction()->assertHasActionErrors(['confirmed']);
    $component->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();

    expect($this->condition->fresh()->amount)->toBe('15.00')
        ->and($this->condition->fresh()->validTo()->toDateString())->toBe('2025-12-31')
        ->and($this->contract->conditions()->reorder()->latest('id')->first()->amount)->toBe('30.50')
        ->and($this->previousYear->fresh()->allocation())->toBe('15.00')
        ->and($this->exercise->fresh()->allocation())->toBe('30.50')
        ->and($closing->fresh()->getAttributes())->toBe($closingBefore)
        ->and($budget->fresh()->getAttributes())->toBe($budgetBefore)
        ->and($actual->fresh()->getAttributes())->toBe($actualBefore)
        ->and($this->contract->fresh()->nextExpiryDate())->toBeNull()
        ->and($item->fresh()->readiness_state->value)->toBe('to_realign');
});

it('keeps month end attribution and retries the same operation without a duplicate', function () {
    $this->condition->update(['amount' => '77.50', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_end']);
    $input = successionInput(['amount' => '122.50', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_end']);
    $action = app(ChangeContractCondition::class);
    $plan = $action->preview($this->contract->fresh(), $this->condition->fresh(), $input);
    $operation = (string) Str::uuid();
    $new = $action->execute($this->actor, $this->contract, $this->condition, $input, $plan->fingerprint(), $plan->effectiveDate, $operation);
    $retry = $action->execute($this->actor, $this->contract, $this->condition, $input, $plan->fingerprint(), $plan->effectiveDate, $operation);

    expect($new->is($retry))->toBeTrue()
        ->and($this->contract->conditions()->count())->toBe(2)
        ->and($this->exercise->fresh()->allocation())->toBe('1425.00')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractConditionChanged)->sole()->new_value['operation_kind'])->toBe('succession');
});

it('restores the previous end date when the unsaved row is removed', function () {
    $component = Livewire::test(EditContract::class, ['record' => $this->contract->getRouteKey()]);
    $previousKey = array_key_first($component->get('data.conditions'));
    $component->callFormComponentAction('conditions', 'add');
    $newKey = array_key_last($component->get('data.conditions'));
    $component->set("data.conditions.{$previousKey}.valid_to", '31/12/2025')
        ->callFormComponentAction('conditions', 'delete', arguments: ['item' => $newKey]);
    expect($component->get('data.conditions'))->toHaveCount(1)
        ->and($component->get("data.conditions.{$previousKey}.valid_to"))->toBeNull()
        ->and($this->condition->fresh()->valid_to)->toBeNull();
});

it('rejects a future boundary inside this month and keeps an anchored month end', function () {
    $this->condition->update(['cycle' => 'monthly', 'valid_from' => '2025-01-30']);
    $action = app(ChangeContractCondition::class);
    expect(fn () => $action->preview($this->contract, $this->condition, successionInput(['requested_date' => '2026-09-30'])))
        ->toThrow(ValidationException::class);
    $plan = $action->preview($this->contract, $this->condition, successionInput(['requested_date' => '2026-02-28']));
    expect($plan->effectiveDate)->toBe('2026-02-28');
});

it('rejects invalid succession dates without changing history', function (string $date) {
    $events = AuditEvent::count();
    expect(fn () => app(ChangeContractCondition::class)->preview($this->contract, $this->condition, successionInput(['requested_date' => $date])))
        ->toThrow(ValidationException::class)
        ->and($this->condition->fresh()->valid_to)->toBeNull()
        ->and($this->contract->conditions()->count())->toBe(1)
        ->and(AuditEvent::count())->toBe($events);
})->with(['2025-01-01', '2024-01-01', '2026-02-01', '2026-01-15']);

it('rejects a closed effective year and a closed year reached only by end attribution', function (string $case) {
    if ($case === 'attribution') {
        $this->condition->update(['attribution_mode' => 'cycle_end']);
        Exercise::factory()->for($this->company)->create(['year' => 2027, 'status' => 'closed']);
    } else {
        closeExerciseFixture($this->previousYear, $this->actor);
        closeExerciseFixture($this->exercise, $this->actor);
    }
    expect(fn () => app(ChangeContractCondition::class)->preview($this->contract, $this->condition->fresh(), successionInput([
        'attribution_mode' => $this->condition->fresh()->attribution_mode, 'valid_to' => '2026-12-31',
    ])))->toThrow(ValidationException::class);
})->with(['effective', 'attribution']);

it('requires a reason for a past succession and leaves future boundaries unchanged', function () {
    $action = app(ChangeContractCondition::class);
    expect(fn () => $action->preview($this->contract, $this->condition, successionInput(['reason' => null])))
        ->toThrow(ValidationException::class);
    $plan = $action->preview($this->contract, $this->condition, successionInput(['requested_date' => '2027-01-01']));
    expect($plan->effectiveDate)->toBe('2027-01-01')->and($plan->minimumDate)->toBe('2026-10-01');
});

it('rolls back the old end date and new condition if recalculation fails', function () {
    $action = app(ChangeContractCondition::class);
    $input = successionInput();
    $plan = $action->preview($this->contract, $this->condition, $input);
    $events = AuditEvent::count();
    AuditEvent::creating(function (AuditEvent $event) {
        if ($event->eventType() === AuditEventType::ContractEstimateRecalculated) {
            throw new RuntimeException('forced failure');
        }
    });
    expect(fn () => $action->execute($this->actor, $this->contract, $this->condition, $input, $plan->fingerprint(), $plan->effectiveDate, (string) Str::uuid()))
        ->toThrow(RuntimeException::class)
        ->and($this->condition->fresh()->valid_to)->toBeNull()
        ->and($this->contract->conditions()->count())->toBe(1)
        ->and($this->exercise->fresh()->allocation())->toBe('15.00')
        ->and(AuditEvent::count())->toBe($events);
});

it('rejects a changed form or a newly closed exercise after reviewing a succession', function (string $change) {
    $action = app(SaveContractEdits::class);
    $original = $action->state($this->contract);
    $data = $original;
    $data['conditions'][0]['valid_to'] = '2025-12-31';
    $data['conditions'][] = ['id' => null, 'amount' => '30.50', 'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-01-01', 'valid_to' => null];
    $review = $action->preview($this->actor, $this->contract, $action->changes($original, $data), ['reason' => 'Rinnovo già avvenuto']);
    if ($change === 'form') {
        $data['conditions'][1]['amount'] = '40.00';
    } else {
        closeExerciseFixture($this->previousYear, $this->actor);
        closeExerciseFixture($this->exercise, $this->actor);
    }
    expect(fn () => $action->execute($this->actor, $this->contract, $original, $this->contract->revision, $data, $review, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and($this->condition->fresh()->valid_to)->toBeNull();
})->with(['form', 'closure']);
