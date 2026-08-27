<?php

use App\Actions\CreateCompany;
use App\Domain\Company\Capability;
use App\Filament\Pages\CompanyAccess;
use App\Models\CompanyCapability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('synchronizes beneficiary capabilities from the company access page', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $beneficiary = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $this->actingAs($administrator);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CompanyAccess::class)
        ->fillForm([
            'beneficiary_id' => $beneficiary->id,
            'capabilities' => [
                Capability::View->value,
                Capability::ManageSettings->value,
            ],
            'reason' => 'Operatore impostazioni',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Capacità aggiornate');

    expect($beneficiary->hasCapability($company, Capability::View))->toBeTrue()
        ->and($beneficiary->hasCapability($company, Capability::ManageSettings))->toBeTrue();
});

it('does not expose the access page without manage permissions', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $viewer = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
        'capability' => Capability::View,
    ]);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    expect(CompanyAccess::canAccess())->toBeFalse();

    $this->get(CompanyAccess::getUrl(tenant: $company))
        ->assertForbidden();
});
