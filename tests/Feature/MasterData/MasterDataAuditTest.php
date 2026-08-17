<?php

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Actions\MasterData\CreateSupplierContact;
use App\Actions\MasterData\RenameCostCenter;
use App\Actions\MasterData\SetCostCenterArchived;
use App\Actions\MasterData\SetSupplierArchived;
use App\Actions\MasterData\UpdateSupplier;
use App\Actions\MasterData\UpdateSupplierContact;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Filament\Pages\CompanyAudit;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function grantMasterDataAuditCapabilities(User $user, Company $company): void
{
    foreach ([Capability::View, Capability::ManageMasterData] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('records every S2 event with a complete immutable operation envelope', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantMasterDataAuditCapabilities($actor, $company);

    $supplier = app(CreateSupplier::class)->execute($actor, $company, [
        'legal_name' => 'Fornitore Audit',
        'vat_number' => null,
        'notes' => null,
    ], (string) Str::uuid());
    app(UpdateSupplier::class)->execute($actor, $supplier, [
        'legal_name' => 'Fornitore Audit Aggiornato',
        'vat_number' => null,
        'notes' => null,
    ], (string) Str::uuid());
    $contact = app(CreateSupplierContact::class)->execute($actor, $supplier, [
        'first_name' => 'Ada',
    ], (string) Str::uuid());
    app(UpdateSupplierContact::class)->execute($actor, $contact, [
        'first_name' => 'Ada Maria',
    ], (string) Str::uuid());
    $costCenter = app(CreateCostCenter::class)->execute($actor, $company, [
        'name' => 'Operations',
    ], (string) Str::uuid());
    app(RenameCostCenter::class)->execute($actor, $costCenter, [
        'name' => 'Operations Italia',
    ], (string) Str::uuid());
    app(SetSupplierArchived::class)->execute($actor, $supplier, true, (string) Str::uuid());
    app(SetSupplierArchived::class)->execute($actor, $supplier, false, (string) Str::uuid());
    app(SetCostCenterArchived::class)->execute($actor, $costCenter, true, (string) Str::uuid());
    app(SetCostCenterArchived::class)->execute($actor, $costCenter, false, (string) Str::uuid());

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(10)
        ->and($events->pluck('event_type')->all())->toBe([
            AuditEventType::SupplierCreated,
            AuditEventType::SupplierUpdated,
            AuditEventType::SupplierContactCreated,
            AuditEventType::SupplierContactUpdated,
            AuditEventType::CostCenterCreated,
            AuditEventType::CostCenterRenamed,
            AuditEventType::SupplierArchived,
            AuditEventType::SupplierRestored,
            AuditEventType::CostCenterArchived,
            AuditEventType::CostCenterRestored,
        ]);

    foreach ($events as $event) {
        expect($event->company_id)->toBe($company->id)
            ->and($event->actor_id)->toBe($actor->id)
            ->and($event->operation_id)->toBeString()
            ->and(Str::isUuid($event->operation_id))->toBeTrue()
            ->and($event->subject_type)->toBeString()
            ->and($event->subject_id)->toBeInt()
            ->and($event->affected_exercise_ids)->toBe([])
            ->and($event->effective_from->toDateString())->toBe(now('Europe/Rome')->toDateString())
            ->and($event->effective_to)->toBeNull()
            ->and($event->allocated_impact_by_exercise)->toBe([])
            ->and($event->actual_impact_by_exercise)->toBe([])
            ->and($event->reason)->toBeNull()
            ->and($event->reference_type)->toBeNull()
            ->and($event->reference_id)->toBeNull();
    }

    expect($events->pluck('operation_id')->unique())->toHaveCount(10)
        ->and(fn () => $events->firstOrFail()->forceFill(['reason' => 'alterato'])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $events->firstOrFail()->delete())
        ->toThrow(LogicException::class);
});

it('renders S2 history newest first in Italian and only for the current company', function () {
    $actor = User::factory()->create(['name' => 'Autore Audit']);
    $companyA = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $companyB = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantMasterDataAuditCapabilities($actor, $companyA);
    grantMasterDataAuditCapabilities($actor, $companyB);
    $supplier = app(CreateSupplier::class)->execute($actor, $companyA, [
        'legal_name' => 'Fornitore Timeline',
        'vat_number' => null,
        'notes' => null,
    ], (string) Str::uuid());
    app(UpdateSupplier::class)->execute($actor, $supplier, [
        'legal_name' => 'Fornitore Timeline Nuovo',
        'vat_number' => null,
        'notes' => null,
    ], (string) Str::uuid());
    app(CreateSupplier::class)->execute($actor, $companyB, [
        'legal_name' => 'Segreto altra Azienda',
        'vat_number' => null,
        'notes' => null,
    ], (string) Str::uuid());
    $eventsA = AuditEvent::query()->whereBelongsTo($companyA)->orderByDesc('id')->get();
    $eventsB = AuditEvent::query()->whereBelongsTo($companyB)->get();
    $created = $eventsA->last();
    $this->actingAs($actor);
    Filament::setTenant($companyA);

    Livewire::test(CompanyAudit::class)
        ->assertCanSeeTableRecords($eventsA, inOrder: true)
        ->assertCanNotSeeTableRecords($eventsB)
        ->assertTableColumnFormattedStateSet('event_type', 'Fornitore creato', $created)
        ->assertTableColumnStateSet('subject', 'Fornitore #'.$supplier->id, $created)
        ->assertTableColumnFormattedStateSet('effective_from', now('Europe/Rome')->format('d/m/Y'), $created)
        ->assertTableColumnStateSet(
            'new_value',
            'Ragione Sociale: Fornitore Timeline · Partita IVA: — · Note: — · Stato: Attivo',
            $created,
        )
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});
