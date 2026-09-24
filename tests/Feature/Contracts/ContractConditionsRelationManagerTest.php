<?php

use App\Actions\Operations\CreateContract;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractConditionsRelationManager;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{manager: User, company: Company, contract: Contract} */
function relationManagerContractFixture(?string $initialValidTo = null, string $contractualStartDate = '2026-01-01'): array
{
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = app(CreateContract::class)->execute($manager, $company, [
        'title' => 'Contratto con condizioni economiche',
        'supplier_id' => $supplier->id,
        'contractual_start_date' => $contractualStartDate,
        'next_expiry_date' => null,
        'renewal_effective_from' => $contractualStartDate,
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
        'notice_days' => null,
        'condition' => [
            'amount' => '10.00',
            'cycle' => 'monthly',
            'attribution_mode' => 'cycle_start',
            'valid_from' => $contractualStartDate,
            'valid_to' => $initialValidTo,
        ],
        'classifications' => [(string) $exercise->id => null],
    ], (string) Str::uuid());

    test()->actingAs($manager);
    Filament::setTenant($company->tenantCompany);

    return compact('manager', 'company', 'contract');
}

it('creates a condition in a free interval and annuls it without raw edit or delete', function () {
    ['contract' => $contract] = relationManagerContractFixture('2026-06-30');
    $firstCondition = $contract->conditions()->sole();

    $component = Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('createCondition', data: [
            'amount' => '20.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-07-01', 'valid_to' => null,
        ])
        ->assertHasNoTableActionErrors()
        ->assertTableActionNotMounted('createCondition')
        ->assertNotified('Condizione Creata');

    $conditions = $contract->conditions()->orderBy('valid_from')->get();
    $newCondition = $conditions->last();

    expect($conditions)->toHaveCount(2)
        ->and($firstCondition->refresh()->validFrom()->toDateString())->toBe('2026-01-01')
        ->and($firstCondition->validTo()?->toDateString())->toBe('2026-06-30');

    $component->assertCanSeeTableRecords([$firstCondition, $newCondition])
        ->assertTableActionDoesNotExist('edit', record: $newCondition)
        ->assertTableActionDoesNotExist('delete', record: $newCondition)
        ->callTableAction('annul', record: $newCondition, data: ['reason' => 'Errore materiale'])
        ->assertHasNoTableActionErrors();

    expect($newCondition->refresh()->isAnnulled())->toBeTrue();
});

it('shows an overlap with an open condition inside the create modal without side effects', function () {
    ['contract' => $contract] = relationManagerContractFixture();
    $auditCount = AuditEvent::query()->count();
    $expenseAllocations = Expense::query()->get()
        ->mapWithKeys(fn (Expense $expense): array => [$expense->id => $expense->allocation()])->all();
    $revision = $contract->revision;

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('createCondition', data: [
            'amount' => '20.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-07-01', 'valid_to' => null,
        ])
        ->assertTableActionMounted('createCondition')
        ->assertHasTableActionErrors([
            'valid_from' => 'Esiste già una condizione valida in questo periodo. Per modificare l’accordo corrente utilizza la modifica del Contratto.',
        ]);

    expect($contract->conditions()->count())->toBe(1)
        ->and($contract->refresh()->revision)->toBe($revision)
        ->and(AuditEvent::query()->count())->toBe($auditCount)
        ->and(Expense::query()->get()->mapWithKeys(fn (Expense $expense): array => [$expense->id => $expense->allocation()])->all())
        ->toBe($expenseAllocations);
});

it('shows the inactive contract error inside the create modal without creating a condition', function () {
    ['contract' => $contract] = relationManagerContractFixture(contractualStartDate: '2027-01-01');
    $conditionCount = $contract->conditions()->count();

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('createCondition', data: [
            'amount' => '20.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2027-07-01', 'valid_to' => null,
        ])
        ->assertTableActionMounted('createCondition')
        ->assertHasTableActionErrors([
            'valid_from' => 'Una nuova condizione richiede un Contratto Attivo.',
        ]);

    expect($contract->conditions()->count())->toBe($conditionCount);
});

it('shows an invalid interval error inside the create modal without side effects', function () {
    ['contract' => $contract] = relationManagerContractFixture('2026-06-30');
    $conditionCount = $contract->conditions()->count();
    $auditCount = AuditEvent::query()->count();

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('createCondition', data: [
            'amount' => '20.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2025-12-31', 'valid_to' => null,
        ])
        ->assertTableActionMounted('createCondition')
        ->assertHasTableActionErrors([
            'valid_from' => 'La condizione non può precedere l’inizio contrattuale.',
        ]);

    expect($contract->conditions()->count())->toBe($conditionCount)
        ->and(AuditEvent::query()->count())->toBe($auditCount);
});

it('hides condition mutations from viewers', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $contract = Contract::factory()->for($company)->create();
    $condition = $contract->conditions()->create([
        'company_id' => $company->id, 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'amount' => '1.00',
        'valid_from' => '2026-01-01', 'created_by_id' => $viewer->id,
    ]);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionHidden('createCondition')
        ->assertTableActionHidden('annul', record: $condition)
        ->assertTableActionDoesNotExist('delete', record: $condition);
});

it('keeps condition history without duplicating the unified edit operations', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create();
    $condition = ContractCondition::factory()->forContract($contract)->create();
    $this->actingAs($manager);
    Filament::setTenant($company->tenantCompany);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertCanSeeTableRecords([$condition])
        ->assertTableActionDoesNotExist('changeAgreement', record: $condition)
        ->assertTableActionDoesNotExist('correctMaterialError', record: $condition)
        ->assertTableActionExists('annul', record: $condition);
});
