<?php

use App\Domain\Company\Capability;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Pages\ContractDeadlines;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractAttachmentsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractClassificationsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ProjectContractLinksRelationManager;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseAttachmentsRelationManager;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseLinesRelationManager;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

function governanceUiContext(bool $manager = true): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => Capability::View]);
    if ($manager) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => Capability::ManageOperations]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $defined = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01', 'next_expiry_date' => '2026-12-31', 'renewal_anchor_date' => '2026-12-31', 'notice_days' => 60,
    ]);
    ContractLifecycleFact::factory()->forContract($defined)->create([
        'type' => 'activation', 'declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01',
    ]);
    $costCenter = CostCenter::factory()->for($company)->create();
    ContractExerciseClassification::factory()->forContractAndExercise($defined, $exercise)->create(['cost_center_id' => $costCenter->id]);
    $undefined = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2027-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
    ]);

    return compact('user', 'company', 'exercise', 'defined', 'undefined', 'costCenter');
}

it('renders every canonical deadline field and filters defined versus undefined expiry without reminder controls', function () {
    extract(governanceUiContext());
    $this->actingAs($user);
    Filament::setTenant($company);

    expect(Contract::query()->where('company_id', $company->id)->active()->count())->toBe(2);

    $component = Livewire::test(ContractDeadlines::class)
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$defined, $undefined])
        ->assertTableColumnExists('supplier.legal_name')
        ->assertTableColumnExists('current_state')
        ->assertTableColumnExists('next_expiry_date')
        ->assertTableColumnExists('notice_limit_date')
        ->assertTableColumnExists('planned_cessation_date')
        ->assertTableColumnExists('cost_center')
        ->assertTableColumnExists('renewal_warning')
        ->assertTableFilterExists('expiry_interval')
        ->assertTableFilterExists('notice_interval')
        ->assertTableFilterExists('automatic_renewal')
        ->assertTableFilterExists('undefined_expiry')
        ->assertTableFilterExists('lifecycle_state')
        ->assertTableFilterExists('supplier')
        ->assertTableFilterExists('cost_center')
        ->filterTable('undefined_expiry')
        ->assertCanSeeTableRecords([$undefined])
        ->assertCanNotSeeTableRecords([$defined]);

    $component->resetTableFilters()
        ->filterTable('expiry_interval', ['from' => '2026-12-01', 'until' => '2026-12-31'])
        ->assertCanSeeTableRecords([$defined])->assertCanNotSeeTableRecords([$undefined])
        ->resetTableFilters()
        ->filterTable('notice_interval', ['from' => '2026-11-01', 'until' => '2026-11-01'])
        ->assertCanSeeTableRecords([$defined])->assertCanNotSeeTableRecords([$undefined])
        ->resetTableFilters()
        ->filterTable('automatic_renewal', true)
        ->assertCanSeeTableRecords([$defined])->assertCanNotSeeTableRecords([$undefined])
        ->resetTableFilters()
        ->filterTable('lifecycle_state', 'planned')
        ->assertCanSeeTableRecords([$undefined])->assertCanNotSeeTableRecords([$defined])
        ->resetTableFilters()
        ->filterTable('supplier', $defined->supplier_id)
        ->assertCanSeeTableRecords([$defined])->assertCanNotSeeTableRecords([$undefined])
        ->resetTableFilters()
        ->filterTable('cost_center', $costCenter->id)
        ->assertCanSeeTableRecords([$defined])->assertCanNotSeeTableRecords([$undefined]);

    $component->assertTableActionDoesNotExist('sendReminder')
        ->assertTableActionDoesNotExist('notify')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('replace');
});

it('registers classification link and private attachment governance surfaces without Sostituisce or delete', function () {
    ['user' => $user, 'company' => $company, 'defined' => $contract] = governanceUiContext();
    $this->actingAs($user);
    Filament::setTenant($company);

    $contractRelations = collect(ContractResource::getRelations());
    expect($contractRelations)->toContain(ContractAttachmentsRelationManager::class)
        ->and($contractRelations)->toContain(
            ContractClassificationsRelationManager::class,
            ProjectContractLinksRelationManager::class,
        )->and(ExpenseResource::getRelations())->toContain(ExpenseAttachmentsRelationManager::class)
        ->and(ProjectResource::getRelations())->toContain(App\Filament\Resources\Projects\RelationManagers\ProjectContractLinksRelationManager::class);

    Livewire::test(ContractClassificationsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionExists('reclassify')
        ->mountTableAction('reclassify', record: $contract->classifications()->sole())
        ->assertSchemaComponentExists('cost_center_id')
        ->assertFormComponentActionHidden('cost_center_id', 'createOption', formName: 'mountedActionSchema0')
        ->assertSchemaComponentExists('impact_preview')
        ->assertTableActionDoesNotExist('delete');

    Livewire::test(ProjectContractLinksRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionExists('linkProject')
        ->assertTableActionDoesNotExist('replace')
        ->assertTableActionDoesNotExist('delete');

    Livewire::test(ContractAttachmentsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionExists('upload')
        ->assertTableActionDoesNotExist('delete');
});

it('creates and selects a Cost Center inline while reclassifying a Contract', function () {
    ['user' => $user, 'company' => $company, 'defined' => $contract] = governanceUiContext();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'capability' => Capability::ManageMasterData,
    ]);
    $this->actingAs($user);
    Filament::setTenant($company);

    $component = Livewire::test(ContractClassificationsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])->mountTableAction('reclassify', record: $contract->classifications()->sole())
        ->assertSchemaComponentHidden('reason')
        ->assertFormComponentActionVisible('cost_center_id', 'createOption', formName: 'mountedActionSchema0')
        ->callFormComponentAction(
            'cost_center_id',
            'createOption',
            ['name' => 'Centro creato in riclassifica'],
            formName: 'mountedActionSchema0',
        )
        ->assertHasNoFormComponentActionErrors();

    $costCenter = CostCenter::query()->where('company_id', $company->id)->where('name', 'Centro creato in riclassifica')->sole();

    $component->assertSchemaStateSet(['cost_center_id' => $costCenter->id]);

    $expense = Expense::factory()->forExercise($contract->classifications()->sole()->exercise)->for($contract)->create();
    ExpenseLine::factory()->actual()->for($expense)->create(['amount' => '10.00']);
    $component->assertSchemaComponentVisible('reason');
    expect(AuditEvent::query()->where('subject_type', CostCenter::class)->where('subject_id', $costCenter->id)->count())->toBe(1);
});

it('shows terminal Archive and ordered Contract Timeline detail while keeping viewer mode read only', function () {
    ['user' => $manager, 'company' => $company, 'defined' => $contract] = governanceUiContext();
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation', 'declared_contractual_date' => '2026-08-01', 'state_change_date' => '2026-08-01', 'reason' => 'Fine',
    ]);
    $events = collect([0, 1])->map(fn (int $sequence): AuditEvent => AuditEvent::withoutEvents(fn () => AuditEvent::query()->create([
        'operation_id' => 'b3f470cf-e12c-4a47-85ba-c71041f359d1',
        'event_sequence' => $sequence,
        'company_id' => $company->id,
        'actor_id' => $manager->id,
        'event_type' => $sequence === 0 ? 'contract_updated' : 'contract_classification_changed',
        'subject_type' => Contract::class,
        'subject_id' => $contract->id,
        'affected_exercise_ids' => [],
        'effective_from' => '2026-08-21',
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ])))->sortByDesc('id')->values();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ViewContract::class, ['record' => $contract->id])
        ->assertActionVisible('archive')
        ->assertActionHidden('restore')
        ->assertActionVisible('createContractActual')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('replace')
        ->assertActionDoesNotExist('sendReminder');
    Livewire::withQueryParams(['contract' => $contract->id])->test(CompanyAudit::class)
        ->assertCanSeeTableRecords($events, inOrder: true)
        ->assertTableColumnExists('event_sequence')
        ->mountTableAction('details', record: $events->first())
        ->assertSchemaComponentExists('detail_operation')
        ->assertSchemaComponentExists('detail_sequence');

    $viewer = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $this->actingAs($viewer);
    Livewire::test(ViewContract::class, ['record' => $contract->id])
        ->assertActionHidden('archive')
        ->assertActionHidden('restore')
        ->assertActionHidden('createContractActual');
    Livewire::test(ContractAttachmentsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionHidden('upload');
});

it('exposes Expense and Line attachment controls to operators and keeps them read only for viewers', function () {
    ['user' => $manager, 'company' => $company, 'exercise' => $exercise] = governanceUiContext();
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ExpenseAttachmentsRelationManager::class, ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class])
        ->assertTableActionExists('upload')
        ->assertTableActionDoesNotExist('delete');
    Livewire::test(ExpenseLinesRelationManager::class, ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class])
        ->assertTableActionVisible('uploadAttachment', record: $line)
        ->assertTableColumnExists('attachments_live')
        ->assertTableActionDoesNotExist('delete', record: $line);

    $viewer = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $this->actingAs($viewer);
    Livewire::test(ExpenseAttachmentsRelationManager::class, ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class])
        ->assertTableActionHidden('upload');
    Livewire::test(ExpenseLinesRelationManager::class, ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class])
        ->assertTableActionHidden('uploadAttachment', record: $line);
});
