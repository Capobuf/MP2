<?php

use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('renders canonical annual deferral values, warning and authorized live action', function (): void {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '90.00']);
    ProjectDeferral::factory()->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '20.00',
        'carryover_state' => 'provisional',
    ]);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);
    app(ExerciseContext::class)->select($company, $destination->id);

    Livewire::test(ViewProject::class, ['record' => $project->id])
        ->assertSuccessful()
        ->assertActionVisible('manage_deferral')
        ->assertSee('Stime')
        ->assertSee('Riporto Ricevuto')
        ->assertSee('Allocato Corrente')
        ->assertSee('Scostamento Operativo')
        ->assertSee('Residuo')
        ->assertSee('Disponibilità Massima Riportabile')
        ->assertSee('Riporto provvisorio superiore al massimo corrente')
        ->mountAction('manage_deferral')
        ->assertSchemaComponentExists('deferral_id')
        ->assertSchemaComponentExists('mode')
        ->assertSchemaComponentExists('deferral_preview')
        ->assertSchemaComponentExists('reason');
});

it('hides direct deferral management from a read-only viewer', function (): void {
    $company = Company::factory()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create();
    ProjectDeferral::factory()->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '10.00',
        'carryover_state' => 'provisional',
    ]);
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProject::class, ['record' => $project->id])
        ->assertActionHidden('manage_deferral');
});
