<?php

use App\Actions\Operations\CreateContract as CreateContractAction;
use App\Actions\Operations\ProcessContractRenewals;
use App\Actions\Operations\SaveContractEdits;
use App\Actions\Operations\UpdateContract;
use App\Actions\Operations\UpdateContractRenewal;
use App\Actions\Operations\UploadAttachment;
use App\Domain\Company\AuditEventType;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
    $this->actor = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantTestPermissions(['company_id' => $this->company->id, 'user' => $this->actor, 'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_OPERATIONS]]);
    $this->exercise = Exercise::factory()->for($this->company)->create(['year' => 2026]);
    $this->nextExercise = Exercise::factory()->for($this->company)->create(['year' => 2027]);
    $this->contract = app(CreateContractAction::class)->execute($this->actor, $this->company, [
        'title' => 'Servizio cloud', 'notes' => 'Accordo originale',
        'supplier_id' => Supplier::factory()->for($this->company)->create()->id,
        'contractual_start_date' => '2026-01-01', 'automatic_renewal' => true,
        'next_expiry_date' => '2026-12-31', 'renewal_duration_months' => 12, 'notice_days' => 30,
        'conditions' => [['amount' => '100.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-01-01']],
    ], (string) Str::uuid());
    $this->condition = $this->contract->conditions()->sole();
    $this->actingAs($this->actor);
    Filament::setTenant($this->company->tenantCompany);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function editContractComponent(Contract $contract)
{
    return Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()]);
}

function changeEditAmount($component, string $amount = '120.00')
{
    $key = array_key_first($component->get('data.conditions'));

    return $component->set("data.conditions.{$key}.amount", $amount);
}

it('shares the creation sections and loads actual conditions and annual classifications without operations', function () {
    $center = CostCenter::factory()->for($this->company)->create(['name' => 'IT']);
    $this->contract->classifications()->where('exercise_id', $this->nextExercise->id)->update(['cost_center_id' => $center->id]);
    $events = AuditEvent::count();
    foreach ([Livewire::test(CreateContract::class), editContractComponent($this->contract)] as $component) {
        $component->assertSee('Dati Principali')->assertSee('Condizioni Economiche')->assertSee('Termini Contrattuali')->assertSee('Avanzate')->assertSee('Allegati');
    }
    editContractComponent($this->contract)
        ->assertFormFieldDoesNotExist('default_cost_center_id')
        ->assertFormSet(['title' => 'Servizio cloud', 'next_expiry_date' => '31/12/2026', 'automatic_renewal' => true])
        ->assertFormSet(function (array $state) use ($center): array {
            expect(array_values($state['conditions'])[0]['amount'])->toBe('100.00');
            expect(array_column(array_values($state['classifications']), 'cost_center_selection'))->toBe(['__unclassified__', (string) $center->id]);

            return [];
        })
        ->call('save')->assertHasNoFormErrors()->assertNotified();
    expect(AuditEvent::count())->toBe($events)->and($this->contract->fresh()->revision)->toBe(0);
});

it('saves descriptive edits without a modal and rejects a supplier change after economic use', function () {
    editContractComponent($this->contract)->fillForm(['title' => 'Nuovo titolo', 'notes' => 'Nuova nota'])
        ->call('save')->assertHasNoFormErrors()->assertNotified();
    expect($this->contract->fresh()->title)->toBe('Nuovo titolo')->and($this->contract->fresh()->notes)->toBe('Nuova nota');
    $other = Supplier::factory()->for($this->company)->create();
    editContractComponent($this->contract)->set('data.supplier_id', $other->id)->call('save')->assertHasFormErrors(['supplier_id']);
    expect($this->contract->fresh()->supplier_id)->toBe($this->contract->supplier_id);
});

it('requires and audits a reason for descriptive edits after Budget without changing the snapshot', function () {
    $row = BudgetSourceRow::factory()->create(['budget_snapshot_id' => BudgetSnapshot::factory()->for(Proposal::factory()->for($this->company)->for($this->exercise))->create()->id, 'company_id' => $this->company->id, 'source_type' => 'contract', 'origin_id' => $this->contract->id, 'origin_key' => $this->contract->originKey()]);
    $snapshot = $row->fresh()->getAttributes();
    $component = editContractComponent($this->contract)->fillForm(['title' => 'Titolo corretto'])->call('save')->assertHasFormErrors(['reason']);
    expect($this->contract->fresh()->title)->toBe('Servizio cloud');
    $component->fillForm(['reason' => 'Precisazione del servizio'])->call('save')->assertHasNoFormErrors();
    expect(AuditEvent::query()->where('event_type', AuditEventType::ContractUpdated)->sole()->reason)->toBe('Precisazione del servizio')
        ->and($row->fresh()->getAttributes())->toBe($snapshot);
});

it('keeps agreement change and material correction distinct and explicitly confirms the preview', function (string $meaning) {
    $component = changeEditAmount(editContractComponent($this->contract));
    $component->call('save')->assertActionMounted('interpretChanges');
    expect($this->condition->fresh()->amount)->toBe('100.00');
    $component->fillForm([
        'meaning' => $meaning, 'requested_date' => '20/08/2026', 'reason' => 'Motivazione esplicita',
        'declared_input_error' => true, 'declared_no_new_agreement' => true,
    ])->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('confirmChanges')
        ->assertMountedActionModalSee('Prorata applicato: no')->assertMountedActionModalSee('Impatto 2026')->assertMountedActionModalSee('Impatto 2027');
    $component->fillForm(['confirmed' => false])->callMountedAction()->assertHasActionErrors(['confirmed']);
    expect($this->condition->fresh()->amount)->toBe('100.00');
    $component->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    if ($meaning === 'change') {
        expect($this->condition->fresh()->amount)->toBe('100.00')->and($this->condition->fresh()->validTo()->toDateString())->toBe('2026-08-31')
            ->and($this->contract->conditions()->reorder()->latest('id')->first()->validFrom()->toDateString())->toBe('2026-09-01')
            ->and($this->contract->conditions()->count())->toBe(2);
    } else {
        expect($this->condition->fresh()->amount)->toBe('120.00')->and($this->contract->conditions()->count())->toBe(1);
    }
})->with(['change', 'correction']);

it('rejects stale review after an exercise revision changes', function () {
    $component = changeEditAmount(editContractComponent($this->contract))->call('save')
        ->fillForm(['meaning' => 'change', 'requested_date' => '01/09/2026'])->callMountedAction()
        ->assertActionMounted('confirmChanges');
    $this->exercise->increment('revision');
    $component->fillForm(['confirmed' => true])->callMountedAction()->assertHasActionErrors(['confirmed']);
    expect($this->contract->conditions()->count())->toBe(1)->and($this->condition->fresh()->amount)->toBe('100.00');
});

it('rejects an unreviewed replacement of form values while the confirmation is open', function () {
    $component = changeEditAmount(editContractComponent($this->contract))->call('save')
        ->fillForm(['meaning' => 'change', 'requested_date' => '01/09/2026'])->callMountedAction()
        ->assertActionMounted('confirmChanges');
    changeEditAmount($component, '999.00')->fillForm(['confirmed' => true])->callMountedAction()->assertHasActionErrors(['confirmed']);
    expect($this->contract->conditions()->count())->toBe(1);
});

it('records renewal history and invalidates a concurrent draft Proposal', function () {
    $draft = Proposal::factory()->for($this->company)->for($this->exercise)->create(['created_by_id' => $this->actor->id]);
    $item = ProposalItem::factory()->for($draft)->create(['company_id' => $this->company->id, 'source_type' => 'contract', 'contract_id' => $this->contract->id, 'baseline_revision' => 0, 'baseline_fingerprint' => hash('sha256', 'baseline')]);
    editContractComponent($this->contract)->fillForm(['notice_days' => 60])->call('save')
        ->assertActionMounted('interpretChanges')->fillForm(['effective_from' => '20/08/2026'])->callMountedAction()
        ->assertHasNoActionErrors()->assertActionMounted('confirmChanges')->assertMountedActionModalSee('Preavviso:')
        ->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->contract->fresh()->notice_days)->toBe(60)->and($this->contract->renewalConfigurations()->count())->toBe(2)
        ->and($item->fresh()->readiness_state->value)->toBe('to_realign');
});

it('previews the annual effect of stopping automatic renewal before saving its history', function () {
    $component = editContractComponent($this->contract)->fillForm(['automatic_renewal' => false])->call('save')
        ->fillForm(['effective_from' => '20/08/2026'])->callMountedAction()->assertActionMounted('confirmChanges');
    expect($component->get('review.plan')[$this->nextExercise->id]['allocation_after'])->toBe('0.00')
        ->and($component->get('review.plan')[$this->nextExercise->id]['allocation_delta'])->toBe('-1200.00')
        ->and($this->contract->fresh()->automatic_renewal)->toBeTrue();
    $component->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->nextExercise->fresh()->allocation())->toBe('0.00')
        ->and($this->contract->renewalConfigurations()->count())->toBe(2);
});

it('explains a duplicate renewal effective date without overwriting history', function () {
    $events = AuditEvent::count();
    editContractComponent($this->contract)->fillForm(['notice_days' => 60])->call('save')
        ->fillForm(['effective_from' => '01/01/2026'])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(AuditEvent::count())->toBe($events)->and($this->contract->renewalConfigurations()->count())->toBe(1)
        ->and($this->contract->fresh()->notice_days)->toBe(30);
});

it('explains pending expiry processing without partially saving and permits editing after processing', function () {
    app(UpdateContractRenewal::class)->execute($this->actor, $this->contract, [
        'automatic_renewal' => true, 'expiry_anchor_date' => '2026-07-31', 'renewal_duration_months' => 12,
        'effective_from' => '2026-07-01', 'expected_revision' => 0, 'impact_confirmed' => true,
    ], (string) Str::uuid());
    $events = AuditEvent::count();
    editContractComponent($this->contract->fresh())->fillForm(['notice_days' => 60, 'title' => 'Non salvare'])->call('save')
        ->fillForm(['effective_from' => '20/08/2026'])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(AuditEvent::count())->toBe($events)->and($this->contract->fresh()->title)->toBe('Servizio cloud');
    app(ProcessContractRenewals::class)->execute($this->actor, $this->contract->fresh(), (string) Str::uuid());
    editContractComponent($this->contract->fresh())->fillForm(['notice_days' => 60])->call('save')
        ->fillForm(['effective_from' => '20/08/2026'])->callMountedAction()->assertActionMounted('confirmChanges')
        ->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->contract->fresh()->notice_days)->toBe(60);
});

it('rejects an agreement change beyond the selected condition interval before confirmation', function () {
    $this->condition->update(['valid_to' => '2026-07-31']);
    $events = AuditEvent::count();
    changeEditAmount(editContractComponent($this->contract))->call('save')
        ->fillForm(['meaning' => 'change', 'requested_date' => '01/09/2026'])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(AuditEvent::count())->toBe($events)->and($this->contract->conditions()->count())->toBe(1)
        ->and($this->condition->fresh()->validTo()->toDateString())->toBe('2026-07-31');
});

it('keeps economic editing blocked on an archived Contract', function () {
    $this->contract->update(['archived_at' => now()]);
    $events = AuditEvent::count();
    changeEditAmount(editContractComponent($this->contract))->call('save')
        ->fillForm(['meaning' => 'change', 'requested_date' => '01/09/2026'])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(AuditEvent::count())->toBe($events)->and($this->condition->fresh()->amount)->toBe('100.00');
});

it('saves a reviewed economic change with descriptions and attachments and rejects a duplicate submission', function () {
    Storage::fake('local');
    $action = app(SaveContractEdits::class);
    $original = $action->state($this->contract);
    $data = $original;
    $data['notes'] = 'Nuovo accordo allegato';
    $data['conditions'][0]['amount'] = '120.00';
    $data['attachments'] = [UploadedFile::fake()->createWithContent('accordo.txt', 'Nuovo accordo')];
    $review = $action->preview($this->actor, $this->contract, $action->changes($original, $data), ['meaning' => 'change', 'requested_date' => '2026-09-01']);
    $operation = (string) Str::uuid();
    $action->execute($this->actor, $this->contract, $original, 0, $data, $review, $operation);
    $events = AuditEvent::count();
    expect($this->contract->fresh()->notes)->toBe('Nuovo accordo allegato')
        ->and($this->contract->conditions()->count())->toBe(2)->and($this->contract->attachments()->count())->toBe(1);
    expect(fn () => $action->execute($this->actor, $this->contract, $original, 0, $data, $review, $operation))
        ->toThrow(ValidationException::class);
    expect(AuditEvent::count())->toBe($events)->and($this->contract->attachments()->count())->toBe(1);
});

it('changes one open annual classification and preserves a closed exercise and its snapshot', function () {
    $snapshot = closeExerciseFixture($this->exercise, $this->actor);
    $before = $snapshot->fresh()->getAttributes();
    $center = CostCenter::factory()->for($this->company)->create();
    $component = editContractComponent($this->contract);
    $key = array_key_first($component->get('data.classifications'));
    $component->set("data.classifications.{$key}.cost_center_selection", (string) $center->id)->call('save')
        ->assertActionMounted('confirmChanges')->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->contract->classifications()->where('exercise_id', $this->exercise->id)->value('cost_center_id'))->toBeNull()
        ->and($this->contract->classifications()->where('exercise_id', $this->nextExercise->id)->value('cost_center_id'))->toBe($center->id)
        ->and($snapshot->fresh()->getAttributes())->toBe($before);
});

it('rejects unsupported dates and mixed impact groups before any side effect', function (string $field) {
    $events = AuditEvent::count();
    $component = editContractComponent($this->contract)->fillForm(['title' => 'Non salvare']);
    if ($field === 'condition') {
        $key = array_key_first($component->get('data.conditions'));
        $component->set("data.conditions.{$key}.valid_from", '02/01/2026');
    } elseif ($field === 'mixed') {
        changeEditAmount($component)->set('data.notice_days', 90);
    } else {
        $component->set('data.contractual_start_date', '02/01/2026');
    }
    $component->call('save')->assertHasFormErrors();
    expect(AuditEvent::count())->toBe($events)->and($this->contract->fresh()->title)->toBe('Servizio cloud');
})->with(['condition', 'start', 'mixed']);

it('refuses an obsolete edit before saving descriptive values', function () {
    $component = editContractComponent($this->contract)->fillForm(['notes' => 'Nota obsoleta']);
    app(UpdateContract::class)->execute($this->actor, $this->contract, ['title' => 'Titolo concorrente', 'notes' => 'Nota concorrente'], (string) Str::uuid());
    $component->call('save')->assertHasFormErrors(['title']);
    expect($this->contract->fresh()->notes)->toBe('Nota concorrente');
});

it('rolls back economic changes descriptions uploads and audit if a later upload fails', function () {
    Storage::fake('local');
    $action = app(SaveContractEdits::class);
    $original = $action->state($this->contract);
    $data = $original;
    $data['title'] = 'Da annullare';
    $data['conditions'][0]['amount'] = '120.00';
    $data['attachments'] = [UploadedFile::fake()->createWithContent('a.txt', 'a'), UploadedFile::fake()->createWithContent('b.txt', 'b')];
    $review = $action->preview($this->actor, $this->contract, $action->changes($original, $data), ['meaning' => 'change', 'requested_date' => '2026-09-01']);
    $events = AuditEvent::count();
    $fail = true;
    Attachment::creating(function (Attachment $attachment) use (&$fail): void {
        if ($fail && $attachment->original_name === 'b.txt') {
            $fail = false;
            throw new RuntimeException('Upload failure');
        }
    });
    expect(fn () => $action->execute($this->actor, $this->contract, $original, 0, $data, $review, (string) Str::uuid()))->toThrow(RuntimeException::class)
        ->and($this->contract->fresh()->title)->toBe('Servizio cloud')->and($this->contract->fresh()->revision)->toBe(0)
        ->and($this->condition->fresh()->valid_to)->toBeNull()->and(ContractCondition::count())->toBe(1)
        ->and(Attachment::count())->toBe(0)->and(AuditEvent::count())->toBe($events)
        ->and(Storage::disk('local')->allFiles('attachments'))->toBe([]);
});

it('requires both material-error declarations and reports a closed-year rejection clearly', function () {
    $component = changeEditAmount(editContractComponent($this->contract))->call('save');
    $component->fillForm(['meaning' => 'correction', 'reason' => 'Errore', 'declared_input_error' => false, 'declared_no_new_agreement' => false])->callMountedAction()
        ->assertHasActionErrors(['declared_input_error', 'declared_no_new_agreement']);
    closeExerciseFixture($this->exercise, $this->actor);
    $component->fillForm(['meaning' => 'correction', 'reason' => 'Errore', 'declared_input_error' => true, 'declared_no_new_agreement' => true])->callMountedAction()
        ->assertHasActionErrors(['reason']);
    expect($this->condition->fresh()->amount)->toBe('100.00');
});

it('uses historical renewal configurations in the economic preview', function () {
    app(UpdateContractRenewal::class)->execute($this->actor, $this->contract, [
        'automatic_renewal' => false, 'expiry_anchor_date' => '2026-12-31', 'effective_from' => '2026-08-20',
        'expected_revision' => 0, 'impact_confirmed' => true,
    ], (string) Str::uuid());
    $component = changeEditAmount(editContractComponent($this->contract->fresh()))->call('save')
        ->fillForm(['meaning' => 'correction', 'reason' => 'Errore', 'declared_input_error' => true, 'declared_no_new_agreement' => true])->callMountedAction()
        ->assertHasNoActionErrors()->assertActionMounted('confirmChanges');
    expect(array_keys($component->get('review.plan.exerciseImpacts')))->toBe([$this->exercise->id]);
    $component->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->nextExercise->fresh()->allocation())->toBe('0.00');
});

it('blocks the supplier when the only economic use is a Budget or Closing snapshot', function (string $reference) {
    $unused = Contract::factory()->for($this->company)->create();
    if ($reference === 'budget') {
        BudgetSourceRow::factory()->create(['budget_snapshot_id' => BudgetSnapshot::factory()->for(Proposal::factory()->for($this->company)->for($this->exercise))->create()->id, 'company_id' => $this->company->id, 'source_type' => 'contract', 'origin_id' => $unused->id, 'origin_key' => $unused->originKey()]);
    } else {
        $snapshot = closeExerciseFixture($this->exercise, $this->actor);
        ClosingSourceRow::query()->create([
            'company_id' => $this->company->id, 'closing_snapshot_id' => $snapshot->id,
            'source_type' => 'contract', 'origin_id' => $unused->id, 'origin_key' => $unused->originKey(),
            'label' => $unused->title, 'detail_version' => 1, 'detail' => [],
            'cost_center_label' => 'Non classificato', 'final_estimates' => '0.00', 'final_allocation' => '0.00', 'closing_actual' => '0.00', 'operational_variance' => '0.00',
        ]);
    }
    $supplier = Supplier::factory()->for($this->company)->create();
    editContractComponent($unused)->set('data.supplier_id', $supplier->id)->call('save')->assertHasFormErrors(['supplier_id']);
    expect($unused->fresh()->supplier_id)->toBe($unused->supplier_id);
})->with(['budget', 'closing']);

it('creates a Cost Center inline in an annual edit row with a separate audit operation', function () {
    grantTestPermissions(['company_id' => $this->company->id, 'user' => $this->actor, 'permissions' => TestPermissions::MANAGE_MASTER_DATA]);
    $component = editContractComponent($this->contract);
    $key = array_key_first($component->get('data.classifications'));
    $component->callFormComponentAction("classifications.{$key}.cost_center_selection", 'createOption', ['name' => 'Nuovo reparto'])
        ->assertHasNoFormComponentActionErrors();
    $center = CostCenter::query()->where('company_id', $this->company->id)->where('name', 'Nuovo reparto')->sole();
    expect((int) $component->get("data.classifications.{$key}.cost_center_selection"))->toBe($center->id);
    $component->call('save')->assertActionMounted('confirmChanges')->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->contract->classifications()->where('exercise_id', $this->exercise->id)->value('cost_center_id'))->toBe($center->id);
});

it('preserves hidden renewal values when saving unchanged or descriptive fields', function (bool $renewal, ?string $expiry) {
    $contract = app(CreateContractAction::class)->execute($this->actor, $this->company, [
        'title' => 'Termini da conservare', 'supplier_id' => $this->contract->supplier_id,
        'contractual_start_date' => '2026-01-01', 'automatic_renewal' => $renewal,
        'next_expiry_date' => $expiry, 'renewal_duration_months' => 12, 'notice_days' => 0,
        'conditions' => [['amount' => '0.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-01-01']],
    ], (string) Str::uuid());
    $before = $contract->only(['next_expiry_date', 'automatic_renewal', 'renewal_duration_months', 'notice_days']);
    $events = AuditEvent::count();
    editContractComponent($contract)->call('save')->assertHasNoFormErrors()->assertActionNotMounted();
    expect(AuditEvent::count())->toBe($events);
    editContractComponent($contract)->fillForm(['title' => 'Titolo aggiornato'])->call('save')->assertHasNoFormErrors()->assertActionNotMounted();
    expect($contract->fresh()->only(array_keys($before)))->toEqual($before)
        ->and($contract->renewalConfigurations()->count())->toBe(1);
})->with([[true, null], [false, null], [false, '2026-12-31']]);

it('carries the descriptive reason into the classification review and audits both changes', function () {
    BudgetSourceRow::factory()->create([
        'budget_snapshot_id' => BudgetSnapshot::factory()->for(Proposal::factory()->for($this->company)->for($this->exercise))->create()->id,
        'company_id' => $this->company->id, 'source_type' => 'contract', 'origin_id' => $this->contract->id, 'origin_key' => $this->contract->originKey(),
    ]);
    $center = CostCenter::factory()->for($this->company)->create();
    $component = editContractComponent($this->contract)->fillForm(['title' => 'Titolo aggiornato', 'reason' => 'Precisazione dopo Budget']);
    $key = array_key_first($component->get('data.classifications'));
    $component->set("data.classifications.{$key}.cost_center_selection", (string) $center->id)->call('save')
        ->assertActionMounted('confirmChanges')->assertFormSet(['reason' => 'Precisazione dopo Budget'])
        ->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->contract->fresh()->title)->toBe('Titolo aggiornato')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractUpdated)->sole()->reason)->toBe('Precisazione dopo Budget')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractClassificationChanged)->sole()->reason)->toBe('Precisazione dopo Budget');
});

it('loads stored attachment content and does not detach it when saving the form', function () {
    Storage::fake('local');
    $attachment = app(UploadAttachment::class)->execute(
        $this->actor, $this->contract, UploadedFile::fake()->createWithContent('accordo.txt', 'Accordo originale'), (string) Str::uuid(),
    );
    $component = editContractComponent($this->contract);
    expect(array_values($component->get('data.attachment_'.$attachment->id)))->toBe([$attachment->storage_path]);
    $component->fillForm(['title' => 'Con allegato'])->call('save')->assertHasNoFormErrors();
    expect($attachment->fresh()->isDetached())->toBeFalse();
    Storage::disk('local')->assertExists($attachment->storage_path);
});

it('invalidates confirmation when a draft Proposal changes without a Contract revision', function () {
    $draft = Proposal::factory()->for($this->company)->for($this->exercise)->create(['created_by_id' => $this->actor->id]);
    $component = changeEditAmount(editContractComponent($this->contract))->call('save')
        ->fillForm(['meaning' => 'change', 'requested_date' => '01/09/2026'])->callMountedAction()->assertActionMounted('confirmChanges');
    $draft->increment('revision');
    $events = AuditEvent::count();
    $component->fillForm(['confirmed' => true])->callMountedAction()->assertHasActionErrors(['confirmed']);
    expect(AuditEvent::count())->toBe($events)->and($this->contract->conditions()->count())->toBe(1);
});

it('keeps an archived Cost Center readable only in its existing annual row', function () {
    $parent = CostCenter::factory()->for($this->company)->create(['name' => 'Servizi']);
    $center = CostCenter::factory()->for($this->company)->create(['name' => 'IT', 'parent_id' => $parent->id, 'archived_at' => now()]);
    $this->contract->classifications()->where('exercise_id', $this->exercise->id)->update(['cost_center_id' => $center->id]);
    $component = editContractComponent($this->contract);
    $keys = array_keys($component->get('data.classifications'));
    $component->assertFormFieldExists("classifications.{$keys[0]}.cost_center_selection", function ($field) use ($center): bool {
        expect($field->getOptions()[$center->id])->toBe('Servizi / IT · Archiviato');

        return true;
    })->assertFormFieldExists("classifications.{$keys[1]}.cost_center_selection", function ($field) use ($center): bool {
        expect($field->getOptions())->not->toHaveKey($center->id);

        return true;
    });
    $events = AuditEvent::count();
    $component->call('save')->assertHasNoFormErrors();
    expect(AuditEvent::count())->toBe($events);
});

it('does not interpret equivalent decimal input as an economic change', function () {
    $events = AuditEvent::count();
    changeEditAmount(editContractComponent($this->contract), '100,00')->call('save')
        ->assertHasNoFormErrors()->assertActionNotMounted();
    expect(AuditEvent::count())->toBe($events)->and($this->contract->fresh()->revision)->toBe(0);
});

it('corrects frequency and attribution through the reviewed canonical operation', function (string $field, string $value) {
    $component = editContractComponent($this->contract);
    $key = array_key_first($component->get('data.conditions'));
    $component->set("data.conditions.{$key}.{$field}", $value)->call('save')
        ->assertActionMounted('interpretChanges')
        ->fillForm(['meaning' => 'correction', 'reason' => 'Trascrizione errata', 'declared_input_error' => true, 'declared_no_new_agreement' => true])
        ->callMountedAction()->assertActionMounted('confirmChanges')
        ->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->condition->fresh()->getAttribute($field))->toBe($value)
        ->and($this->contract->conditions()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractConditionCorrected)->count())->toBe(1);
})->with([['cycle', 'quarterly'], ['attribution_mode', 'cycle_end']]);

it('reauthorizes an economic confirmation after permissions are revoked', function () {
    $component = changeEditAmount(editContractComponent($this->contract))->call('save')
        ->fillForm(['meaning' => 'change', 'requested_date' => '01/09/2026'])->callMountedAction()->assertActionMounted('confirmChanges')
        ->fillForm(['confirmed' => true]);
    revokeTestPermission($this->actor, 'Update:Contract');
    $events = AuditEvent::count();
    $component->callMountedAction()->assertForbidden();
    expect(AuditEvent::count())->toBe($events)->and($this->contract->conditions()->count())->toBe(1);
});

it('blocks renewal dates that the current backend cannot represent without replacing historical or current terms', function (string $case) {
    if ($case === 'materialized') {
        $this->contract->lifecycleFacts()->create([
            'company_id' => $this->company->id, 'type' => 'renewal', 'declared_contractual_date' => '2026-06-30',
            'renewed_expiry_date' => '2026-06-30', 'renewal_configuration_id' => $this->contract->renewalConfigurations()->sole()->id,
            'created_by_id' => $this->actor->id,
        ]);
    }
    $events = AuditEvent::count();
    $component = editContractComponent($this->contract)->fillForm(['title' => 'Non salvare', 'notice_days' => 60]);
    if ($case === 'past_expiry') {
        $component->fillForm(['next_expiry_date' => '31/07/2026']);
    }
    $component->call('save')->fillForm(['effective_from' => match ($case) {
        'future' => '01/09/2026',
        'materialized' => '01/06/2026',
        default => '20/08/2026',
    }])->callMountedAction()->assertHasActionErrors(['reason']);
    expect(AuditEvent::count())->toBe($events)->and($this->contract->fresh()->title)->toBe('Servizio cloud')
        ->and($this->contract->renewalConfigurations()->count())->toBe(1)->and($this->contract->fresh()->notice_days)->toBe(30);
})->with(['future', 'materialized', 'past_expiry']);

it('keeps the Supplier locked after generated estimates return to zero', function () {
    changeEditAmount(editContractComponent($this->contract), '0.00')->call('save')
        ->fillForm(['meaning' => 'correction', 'reason' => 'Importo errato', 'declared_input_error' => true, 'declared_no_new_agreement' => true])
        ->callMountedAction()->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($this->exercise->fresh()->allocation())->toBe('0.00')->and($this->contract->fresh()->hasEconomicUse())->toBeTrue();
    $supplier = Supplier::factory()->for($this->company)->create();
    editContractComponent($this->contract->fresh())->set('data.supplier_id', $supplier->id)->call('save')->assertHasFormErrors(['supplier_id']);
    expect($this->contract->fresh()->supplier_id)->toBe($this->contract->supplier_id);
});

it('preserves the anchored renewal calendar when only notice changes after a short month', function () {
    CarbonImmutable::setTestNow('2026-02-10 10:00:00 Europe/Rome');
    $contract = app(CreateContractAction::class)->execute($this->actor, $this->company, [
        'title' => 'Rinnovo mensile', 'supplier_id' => $this->contract->supplier_id,
        'contractual_start_date' => '2026-01-01', 'automatic_renewal' => true,
        'next_expiry_date' => '2026-01-31', 'renewal_duration_months' => 1, 'notice_days' => 10,
        'conditions' => [['amount' => '100.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-01-01']],
    ], (string) Str::uuid());
    expect($contract->nextExpiryDate()->toDateString())->toBe('2026-02-28');
    editContractComponent($contract)->fillForm(['notice_days' => 15])->call('save')
        ->fillForm(['effective_from' => '10/02/2026'])->callMountedAction()->assertActionMounted('confirmChanges')
        ->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($contract->fresh()->renewalAnchorDate()->toDateString())->toBe('2026-01-31')
        ->and($contract->fresh()->nextExpiryDate()->toDateString())->toBe('2026-02-28')
        ->and($contract->renewalConfigurations()->reorder()->latest('id')->first()->expiryAnchorDate()->toDateString())->toBe('2026-01-31');
    $event = AuditEvent::query()->where('event_type', AuditEventType::ContractRenewalChanged)->sole();
    expect($event->previous_value['notice_days'])->toBe(10)->and($event->new_value['notice_days'])->toBe(15)
        ->and($event->new_value['renewal_duration_months'])->toBe(1);
    CarbonImmutable::setTestNow('2026-03-01 10:00:00 Europe/Rome');
    $renewed = app(ProcessContractRenewals::class)->execute($this->actor, $contract->fresh(), (string) Str::uuid());
    expect($renewed->nextExpiryDate()->toDateString())->toBe('2026-03-31');
});
