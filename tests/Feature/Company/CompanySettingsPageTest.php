<?php

use App\Actions\CreateCompany;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Filament\Pages\CompanySettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires and uses a timezone preview from the settings page', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $this->actingAs($administrator);
    Filament::setTenant(($company)->tenantCompany);

    $page = Livewire::test(CompanySettings::class)
        ->fillForm([
            'overspend_note_required' => true,
            'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Blocking->value,
            'timezone' => 'Europe/Paris',
        ]);

    $page->call('save')->assertHasFormErrors(['timezone']);

    $page->call('previewTimezone')
        ->assertSet('previewedTimezone', 'Europe/Paris')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Impostazioni aggiornate')
        ->assertFormSet([
            'overspend_note_required' => true,
            'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Blocking->value,
            'timezone' => 'Europe/Paris',
        ]);

    $company->refresh();
    expect($company->timezone)->toBe('Europe/Paris')
        ->and($company->overspend_note_required)->toBeTrue()
        ->and($company->unclassified_closing_policy)->toBe(ClosingUnclassifiedPolicy::Blocking);
});

it('does not expose fixed domain behavior as settings', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $this->actingAs($administrator);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CompanySettings::class)
        ->assertFormFieldExists('overspend_note_required')
        ->assertFormFieldExists('unclassified_closing_policy')
        ->assertFormFieldExists('timezone')
        ->assertFormFieldDoesNotExist('currency')
        ->assertFormFieldDoesNotExist('forecast')
        ->assertFormFieldDoesNotExist('prorata');
});
