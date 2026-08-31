<?php

use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantExerciseResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] : [TestPermissions::VIEW] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $user,
            'permissions' => $capability,
        ]);
    }
}

it('lists and resolves exercises only inside the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantExerciseResource($viewer, $companyA, false);
    $exerciseA = Exercise::factory()->for($companyA)->create();
    $exerciseB = Exercise::factory()->for($companyB)->create();
    $this->actingAs($viewer);
    Filament::setTenant(($companyA)->tenantCompany);

    Livewire::test(ListExercises::class)
        ->assertCanSeeTableRecords([$exerciseA])
        ->assertCanNotSeeTableRecords([$exerciseB])
        ->assertTableActionDoesNotExist('delete', record: $exerciseA)
        ->assertTableActionDoesNotExist('edit', record: $exerciseA);

    Livewire::test(ViewExercise::class, ['record' => $exerciseA->getRouteKey()])->assertSuccessful();
    $this->get(ExerciseResource::getUrl('view', ['record' => $exerciseB], tenant: $companyA))->assertNotFound();
});

it('creates only a year and exposes no later-slice fields or edit route', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExerciseResource($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateExercise::class)
        ->assertFormFieldExists('year')
        ->assertFormFieldDoesNotExist('budget')
        ->assertFormFieldDoesNotExist('closing')
        ->assertFormFieldDoesNotExist('carryover')
        ->assertFormFieldDoesNotExist('reprogramming')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('project_id')
        ->assertFormFieldDoesNotExist('contract_id')
        ->fillForm(['year' => 2032])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Exercise::query()->sole()->year)->toBe(2032)
        ->and(array_key_exists('edit', ExerciseResource::getPages()))->toBeFalse();
});

it('creates a distinct exercise after save and create another', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExerciseResource($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateExercise::class)
        ->fillForm(['year' => 2032])
        ->call('create', true)
        ->assertHasNoFormErrors()
        ->fillForm(['year' => 2033])
        ->call('create', true)
        ->assertHasNoFormErrors();

    expect(Exercise::query()->orderBy('year')->pluck('year')->all())
        ->toBe([2032, 2033]);
});
