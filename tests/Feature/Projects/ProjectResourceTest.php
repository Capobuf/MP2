<?php

use App\Domain\Company\Capability;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\User;
use App\Support\ExerciseContext;
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
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('notes')
        ->assertFormFieldExists('initial_state')
        ->assertFormFieldExists('initial_effective_date')
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertFormFieldExists('cost_center_id')
        ->assertFormComponentActionHidden('cost_center_id', 'createOption')
        ->assertFormFieldDoesNotExist('supplier_id')
        ->assertFormFieldDoesNotExist('carryover')
        ->assertFormFieldDoesNotExist('carryover_mode')
        ->assertFormFieldDoesNotExist('reprogramming')
        ->assertFormFieldDoesNotExist('reprogrammed_amount')
        ->assertFormFieldDoesNotExist('contract_id')
        ->assertFormFieldDoesNotExist('contract_ids')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormFieldDoesNotExist('closing')
        ->assertFormFieldDoesNotExist('closing_snapshot_id')
        ->assertFormFieldDoesNotExist('forecast')
        ->assertFormFieldDoesNotExist('attachment')
        ->assertFormFieldDoesNotExist('attachments')
        ->assertFormFieldDoesNotExist('cost_center_percentage')
        ->assertFormFieldDoesNotExist('report')
        ->assertDontSee('Sospeso')
        ->fillForm([
            'title' => 'Nuovo laboratorio',
            'description' => 'Allestimento',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2027-01-01',
            'cost_center_id' => $costCenter->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::query()->count())->toBe(1)
        ->and(ProjectExerciseClassification::query()->count())->toBe(1)
        ->and(ProjectExerciseClassification::query()->sole()->exercise_id)->toBe($exercise->id)
        ->and(Expense::query()->count())->toBe(0);
});

it('overrides browser Exercise state and blocks creation for a closed global Exercise', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectResource($manager, $company);
    $selected = Exercise::factory()->for($company)->create(['year' => 2026]);
    $injected = Exercise::factory()->for($company)->create(['year' => 2027]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $selected->id);

    Livewire::test(CreateProject::class)
        ->set('data.exercise_id', $injected->id)
        ->fillForm([
            'title' => 'Contesto autoritativo',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ProjectExerciseClassification::query()->sole()->exercise_id)->toBe($selected->id);

    $closed = Exercise::factory()->for($company)->create([
        'year' => 2025,
        'status' => ExerciseStatus::Closed,
    ]);
    app(ExerciseContext::class)->select($company, $closed->id);

    Livewire::test(ListProjects::class)->assertActionDisabled('create');
    Livewire::test(CreateProject::class)
        ->fillForm([
            'title' => 'Non consentito',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2025-01-01',
        ])
        ->call('create')
        ->assertHasErrors(['exercise_id']);

    expect(Project::query()->count())->toBe(1);
});

it('creates and selects a Cost Center inline with a distinct operation', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectResource($manager, $company);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $manager->id,
        'capability' => Capability::ManageMasterData,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateProject::class)
        ->assertFormComponentActionVisible('cost_center_id', 'createOption')
        ->callFormComponentAction('cost_center_id', 'createOption', ['name' => 'Centro inline']);

    $costCenter = CostCenter::query()->sole();
    $masterDataOperationId = AuditEvent::query()->where('subject_type', CostCenter::class)->sole()->operation_id;

    $component
        ->assertFormSet(['cost_center_id' => $costCenter->id])
        ->fillForm([
            'title' => 'Progetto inline',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ProjectExerciseClassification::query()->sole()->cost_center_id)->toBe($costCenter->id)
        ->and(AuditEvent::query()->where('subject_type', Project::class)->sole()->operation_id)
        ->not->toBe($masterDataOperationId);
});

it('creates a distinct Project after save and create another', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2027]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(CreateProject::class)
        ->fillForm([
            'title' => 'Primo progetto',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2027-01-01',
        ])
        ->call('create', true)
        ->assertHasNoFormErrors()
        ->fillForm([
            'title' => 'Secondo progetto',
            'initial_state' => ProjectState::Planned->value,
            'initial_effective_date' => '2027-01-01',
        ])
        ->call('create', true)
        ->assertHasNoFormErrors();

    expect(Project::query()->orderBy('id')->pluck('title')->all())
        ->toBe(['Primo progetto', 'Secondo progetto'])
        ->and(ProjectExerciseClassification::query()->count())->toBe(2);
});

it('lists views and resolves Projects only inside the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $companyB = Company::factory()->create();
    grantProjectResource($viewer, $companyA, false);
    $past = Exercise::factory()->for($companyA)->create(['year' => 2025]);
    $current = Exercise::factory()->for($companyA)->create(['year' => 2026]);
    $future = Exercise::factory()->for($companyA)->create(['year' => 2027]);
    $projectA = Project::factory()->for($companyA)->create([
        'title' => 'Visibile',
        'initial_state' => ProjectState::Planned,
        'initial_effective_date' => '2027-01-01',
    ]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($projectA, $past)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($projectA, $current)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($projectA, $future)->create();
    ProjectTransition::factory()->forProject($projectA)->create([
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Open,
        'effective_date' => '2027-06-01',
        'created_by_id' => $viewer->id,
    ]);
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
        ->assertSeeHtml('class="mp2-object-header')
        ->assertSeeHtml('data-object-icon="project"')
        ->assertSee('Situazione Esercizio corrente')
        ->assertSee('project:'.$projectA->id)
        ->assertSee('Assente alla data')
        ->assertSee('31/12/2025')
        ->assertSee('17/08/2026')
        ->assertSee('1° gennaio dell’Esercizio futuro')
        ->assertSee('01/06/2027: Pianificato → Aperto')
        ->assertDontSee('Riporto')
        ->assertDontSee('Riprogrammazione')
        ->assertDontSee('Contratto')
        ->assertDontSee('Proposta')
        ->assertDontSee('Budget')
        ->assertDontSee('Chiusura')
        ->assertDontSee('Forecast')
        ->assertDontSee('Allegati')
        ->assertDontSee('Fornitore del Progetto')
        ->assertDontSee('Ripartizione percentuale')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('closeExercise')
        ->assertActionDoesNotExist('approveBudget')
        ->assertActionDoesNotExist('exportReport');

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
        ->assertFormFieldDoesNotExist('supplier_id')
        ->assertFormFieldDoesNotExist('carryover')
        ->assertFormFieldDoesNotExist('reprogramming')
        ->assertFormFieldDoesNotExist('contract_id')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormFieldDoesNotExist('closing')
        ->assertFormFieldDoesNotExist('forecast')
        ->assertFormFieldDoesNotExist('attachments')
        ->assertFormFieldDoesNotExist('cost_center_percentage')
        ->fillForm(['title' => 'Dopo', 'description' => 'Aggiornata'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->refresh()->title)->toBe('Dopo')
        ->and($project->revision)->toBe(1);
});
