<?php

use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('shows archive only for terminal active Projects and restore only for archived Projects', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $open = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open, 'initial_effective_date' => '2026-01-01']);
    $closed = Project::factory()->for($company)->create(['initial_state' => ProjectState::Closed, 'initial_effective_date' => '2026-01-01']);
    $archived = Project::factory()->for($company)->archived()->create(['initial_state' => ProjectState::Cancelled, 'initial_effective_date' => '2026-01-01']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProject::class, ['record' => $open->getRouteKey()])
        ->assertActionHidden('archive')
        ->assertActionHidden('restore')
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('carryover')
        ->assertActionDoesNotExist('reprogram')
        ->assertActionDoesNotExist('linkContract')
        ->assertActionDoesNotExist('createProposal')
        ->assertActionDoesNotExist('approveBudget')
        ->assertActionDoesNotExist('closeExercise')
        ->assertActionDoesNotExist('uploadAttachment')
        ->assertActionDoesNotExist('forecast')
        ->assertActionDoesNotExist('exportReport');
    Livewire::test(ViewProject::class, ['record' => $closed->getRouteKey()])
        ->assertActionVisible('archive')
        ->callAction('archive')
        ->assertHasNoActionErrors()
        ->assertNotified('Progetto archiviato');
    Livewire::test(ViewProject::class, ['record' => $archived->getRouteKey()])
        ->assertActionVisible('restore')
        ->callAction('restore')
        ->assertHasNoActionErrors()
        ->assertNotified('Progetto ripristinato');
});

it('keeps lifecycle actions hidden from a read-only viewer and exposes no delete', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Closed]);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHidden('archive')
        ->assertActionHidden('restore')
        ->assertActionDoesNotExist('delete');
    Livewire::test(ListProjects::class)
        ->assertTableActionDoesNotExist('delete', record: $project);
});
