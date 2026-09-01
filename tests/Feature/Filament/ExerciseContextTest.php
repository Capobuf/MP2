<?php

use App\Domain\Expenses\ExerciseStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\EconomicSummary;
use App\Livewire\ExerciseContextSelector;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

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
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ExerciseContextSelector::class)
        ->assertSet('exerciseId', $exercise->id)
        ->assertSee('2026 · Aperto')
        ->assertSeeHtml('aria-label="Seleziona Esercizio"')
        ->assertDontSee('Gestisci Esercizi')
        ->assertDontSee('Crea Esercizio');

    Livewire::test(EconomicSummary::class)
        ->assertSee('Quadro Economico')
        ->assertSee('Budget Selezionato')
        ->assertSee('Allocato Corrente')
        ->assertSee('Effettivo')
        ->assertSee('Scostamento Operativo');
});

it('renders only the native tenant switcher for the Company context', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create(['name' => 'Azienda Demo']);

    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_SETTINGS, TestPermissions::MANAGE_PERMISSIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $administrator,
            'permissions' => $capability,
        ]);
    }

    $this->actingAs($administrator);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    $this->get(Dashboard::getUrl(tenant: $company->tenantCompany))
        ->assertOk()
        ->assertSee('Azienda Demo')
        ->assertSee('fi-tenant-menu', escape: false)
        ->assertSee('Piattaforma')
        ->assertSee('href="'.Filament::getPanel('platform')->getUrl().'"', escape: false)
        ->assertDontSee('Impostazioni Azienda')
        ->assertDontSee('Crea Azienda')
        ->assertDontSee('Gestisci Esercizi');
});

it('does not expose the Platform link to a tenant user', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    $this->get(Dashboard::getUrl(tenant: $company->tenantCompany))
        ->assertOk()
        ->assertDontSee('Piattaforma');
});

it('orders Exercises by descending year in the selector', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Exercise::factory()->for($company)->create(['year' => 2024]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    Exercise::factory()->for($company)->create(['year' => 2025]);
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    Livewire::test(ExerciseContextSelector::class)
        ->assertSeeInOrder(['2026 · Aperto', '2025 · Aperto', '2024 · Aperto']);
});

it('selects and clears the current Budget from the selector', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $proposal = Proposal::factory()->for($company)->create([
        'exercise_id' => $exercise->id,
        'created_by_id' => $user->id,
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $user->id,
    ]);
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    Livewire::test(ExerciseContextSelector::class)
        ->assertSeeHtml('aria-label="Seleziona Budget"')
        ->call('selectBudget', $budget->id)
        ->assertSet('budgetId', $budget->id);

    expect(session("mp2.budget_context.{$company->id}.{$exercise->id}"))->toBe($budget->id);

    Livewire::test(ExerciseContextSelector::class)
        ->call('clearBudget')
        ->assertSet('budgetId', null);

    expect(session()->has("mp2.budget_context.{$company->id}.{$exercise->id}"))->toBeFalse();
});
