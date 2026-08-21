<?php

use App\Domain\Company\Capability;
use App\Domain\Expenses\ExerciseStatus;
use App\Filament\Pages\CompanyAccess;
use App\Filament\Pages\CompanySettings;
use App\Filament\Widgets\OperationalOverview;
use App\Livewire\ExerciseContextSelector;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\User;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('selects the current-year Exercise by default and persists a choice per Company', function () {
    $companyA = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $companyB = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $past = Exercise::factory()->for($companyA)->create(['year' => 2025, 'status' => ExerciseStatus::Closed]);
    $current = Exercise::factory()->for($companyA)->create(['year' => 2026, 'status' => ExerciseStatus::Open]);
    $other = Exercise::factory()->for($companyB)->create(['year' => 2027, 'status' => ExerciseStatus::Open]);
    $context = app(ExerciseContext::class);

    expect($context->current($companyA)?->is($current))->toBeTrue()
        ->and($context->current($companyB)?->is($other))->toBeTrue();

    $context->select($companyA, $past->id);

    expect($context->current($companyA)?->is($past))->toBeTrue()
        ->and(session("mp2.exercise_context.{$companyA->id}"))->toBe($past->id)
        ->and($context->current($companyB)?->is($other))->toBeTrue();
});

it('rejects an Exercise belonging to another Company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignExercise = Exercise::factory()->for($companyB)->create();

    expect(fn () => app(ExerciseContext::class)->select($companyA, $foreignExercise->id))
        ->toThrow(ValidationException::class);
});

it('renders the Blade and Livewire global context for the current tenant', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Azienda Demo']);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'capability' => Capability::View,
    ]);
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ExerciseContextSelector::class)
        ->assertSet('companyId', $company->id)
        ->assertSet('exerciseId', $exercise->id)
        ->assertSee('Azienda Demo')
        ->assertSee('2026 · Aperto')
        ->assertSeeHtml('aria-label="Esercizio globale"');

    Livewire::test(OperationalOverview::class)
        ->assertSee('Sintesi economica')
        ->assertSee('Stima')
        ->assertSee('Effettivo')
        ->assertSee('Scostamento');
});

it('exposes authorized Company actions in the global context menu', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create(['name' => 'Azienda Demo']);

    foreach ([Capability::View, Capability::ManageSettings, Capability::ManagePermissions] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $administrator->id,
            'capability' => $capability,
        ]);
    }

    $this->actingAs($administrator);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ExerciseContextSelector::class)
        ->assertSee('Impostazioni Azienda')
        ->assertSee('Accessi e capacità')
        ->assertSee('Crea Azienda')
        ->assertSeeHtml('href="'.CompanySettings::getUrl(['tenant' => $company]).'"')
        ->assertSeeHtml('href="'.CompanyAccess::getUrl(['tenant' => $company]).'"')
        ->assertSeeHtml('href="'.Filament::getCurrentPanel()->getTenantRegistrationUrl().'"');
});

it('does not expose unauthorized Company actions', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Azienda Demo']);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'capability' => Capability::View,
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ExerciseContextSelector::class)
        ->assertSeeHtml('aria-label="Azienda corrente"')
        ->assertDontSee('Impostazioni Azienda')
        ->assertDontSee('Accessi e capacità')
        ->assertDontSee('Crea Azienda')
        ->assertDontSeeHtml('aria-label="Apri selettore e azioni Azienda"');
});
