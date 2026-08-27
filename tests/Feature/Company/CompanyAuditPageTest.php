<?php

use App\Actions\CreateCompany;
use App\Actions\SyncCompanyCapabilities;
use App\Actions\UpdateCompanySettings;
use App\Domain\Company\Capability;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Filament\Pages\CompanyAudit;
use App\Models\AuditEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows only current company events newest first with no mutation actions', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $beneficiary = User::factory()->create();
    $companyA = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda A',
        'timezone' => 'Europe/Rome',
    ]);
    $companyB = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda B',
        'timezone' => 'Europe/Rome',
    ]);
    app(SyncCompanyCapabilities::class)->execute(
        $administrator,
        $companyA,
        $beneficiary,
        [Capability::View],
    );
    app(UpdateCompanySettings::class)->execute($administrator, $companyA, [
        'overspend_note_required' => true,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Blocking->value,
        'timezone' => 'Europe/Rome',
    ]);
    $eventsA = AuditEvent::query()->where('company_id', $companyA->id)->orderByDesc('id')->get();
    $eventsB = AuditEvent::query()->where('company_id', $companyB->id)->get();
    $settingEvent = AuditEvent::query()
        ->where('company_id', $companyA->id)
        ->where('setting', 'overspend_note_required')
        ->sole();
    $closingPolicyEvent = AuditEvent::query()
        ->where('company_id', $companyA->id)
        ->where('setting', 'unclassified_closing_policy')
        ->sole();
    $this->actingAs($administrator);
    Filament::setTenant(($companyA)->tenantCompany);

    Livewire::test(CompanyAudit::class)
        ->set('tableRecordsPerPage', 25)
        ->assertCanSeeTableRecords($eventsA, inOrder: true)
        ->assertCanNotSeeTableRecords($eventsB)
        ->assertTableColumnStateSet('previous_value', 'No', $settingEvent)
        ->assertTableColumnFormattedStateSet('previous_value', 'No', $settingEvent)
        ->assertTableColumnStateSet('new_value', 'Sì', $settingEvent)
        ->assertTableColumnFormattedStateSet('new_value', 'Sì', $settingEvent)
        ->assertTableColumnStateSet('previous_value', 'Avviso', $closingPolicyEvent)
        ->assertTableColumnFormattedStateSet('previous_value', 'Avviso', $closingPolicyEvent)
        ->assertTableColumnStateSet('new_value', 'Blocco', $closingPolicyEvent)
        ->assertTableColumnFormattedStateSet('new_value', 'Blocco', $closingPolicyEvent)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

it('exposes audit to a company viewer but not an unrelated user', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $viewer = User::factory()->create();
    $unrelated = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    app(SyncCompanyCapabilities::class)->execute(
        $administrator,
        $company,
        $viewer,
        [Capability::View],
    );

    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);
    expect(CompanyAudit::canAccess())->toBeTrue();

    $this->actingAs($unrelated);
    expect(CompanyAudit::canAccess())->toBeFalse();
});
