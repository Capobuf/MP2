<?php

use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-17 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function grantProjectResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageOperations] : [Capability::View] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates a Project from the tenant UI without future-slice or destructive fields', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2027]);
    $costCenter = CostCenter::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('notes')
        ->assertFormFieldExists('initial_state')
        ->assertFormFieldExists('initial_effective_date')
        ->assertFormFieldExists('exercise_id')
        ->assertFormFieldExists('cost_center_id')
        ->assertFormFieldDoesNotExist('supplier_id')
        ->assertFormFieldDoesNotExist('carryover')
        ->assertFormFieldDoesNotExist('reprogramming')
        ->assertFormFieldDoesNotExist('contract_id')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormFieldDoesNotExist('closing')
        ->assertFormFieldDoesNotExist('forecast')
        ->assertFormFieldDoesNotExist('attachment')
        ->fillForm([
            'title' => 'Nuovo laboratorio',
            'description' => 'Allestimento',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2027-01-01',
            'exercise_id' => $exercise->id,
            'cost_center_id' => $costCenter->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::query()->count())->toBe(1)
        ->and(ProjectExerciseClassification::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(0);
});

it('lists views and resolves Projects only inside the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $companyB = Company::factory()->create();
    grantProjectResource($viewer, $companyA, false);
    $past = Exercise::factory()->for($companyA)->create(['year' => 2025]);
    $current = Exercise::factory()->for($companyA)->create(['year' => 2026]);
    $projectA = Project::factory()->for($companyA)->create([
        'title' => 'Visibile',
        'initial_state' => ProjectState::Planned,
        'initial_effective_date' => '2027-01-01',
    ]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($projectA, $past)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($projectA, $current)->create();
    $projectB = Project::factory()->for($companyB)->create(['title' => 'Segreto']);
    $this->actingAs($viewer);
    Filament::setTenant($companyA);

    Livewire::test(ListProjects::class)
        ->assertCanSeeTableRecords([$projectA])
        ->assertCanNotSeeTableRecords([$projectB])
        ->assertTableActionDoesNotExist('delete', record: $projectA)
        ->assertTableActionHidden('edit', record: $projectA);

    Livewire::test(ViewProject::class, ['record' => $projectA->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('project:'.$projectA->id)
        ->assertSee('Assente alla data')
        ->assertSee('31/12/2025')
        ->assertSee('17/08/2026')
        ->assertDontSee('Riporto')
        ->assertDontSee('Contratto');

    $this->get(ProjectResource::getUrl('view', ['record' => $projectB], tenant: $companyA))->assertNotFound();
    $this->get(ProjectResource::getUrl('edit', ['record' => $projectA], tenant: $companyA))->assertForbidden();
});

it('allows descriptive edit to an operator without exposing lifecycle fields', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectResource($manager, $company);
    $project = Project::factory()->for($company)->create(['title' => 'Prima']);
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('notes')
        ->assertFormFieldDoesNotExist('initial_state')
        ->assertFormFieldDoesNotExist('initial_effective_date')
        ->assertFormFieldDoesNotExist('archived_at')
        ->assertFormFieldDoesNotExist('cost_center_id')
        ->fillForm(['title' => 'Dopo', 'description' => 'Aggiornata'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->refresh()->title)->toBe('Dopo')
        ->and($project->revision)->toBe(1);
});
