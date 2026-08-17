<?php

use App\Actions\CreateCompany;
use App\Domain\Company\Capability;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows the platform administrator to register a company through Filament', function () {
    $administrator = User::factory()->platformAdmin()->create();

    $this->actingAs($administrator);

    Livewire::test(RegisterCompany::class)
        ->fillForm([
            'name' => 'Azienda UI',
            'timezone' => 'Europe/Rome',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $company = Company::query()->sole();

    expect($administrator->canAccessTenant($company))->toBeTrue();
});

it('hides company registration from ordinary users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/new')
        ->assertForbidden();
});

it('rejects a guessed tenant URL outside the users view assignments', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $companyA = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda A',
        'timezone' => 'Europe/Rome',
    ]);
    $companyB = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda B',
        'timezone' => 'Europe/Rome',
    ]);
    $user = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $companyA->id,
        'user_id' => $user->id,
        'capability' => Capability::View,
    ]);

    $this->actingAs($user)
        ->get(Filament::getUrl($companyA))
        ->assertOk();

    $this->actingAs($user)
        ->get(Filament::getUrl($companyB))
        ->assertNotFound();
});
