<?php

use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\ProjectTransitionsRelationManager;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function grantProjectTransitionResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageOperations] : [Capability::View] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-17 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('manages transitions explicitly without edit or delete and only changes future facts', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantProjectTransitionResource($manager, $company);
    $project = Project::factory()->for($company)->create([
        'initial_state' => ProjectState::Planned,
        'initial_effective_date' => '2026-01-01',
    ]);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ProjectTransitionsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->callTableAction('createTransition', data: [
        'to_state' => 'open',
        'effective_date' => '2026-10-01',
        'reason' => null,
    ])->assertHasNoTableActionErrors();

    $future = ProjectTransition::query()->sole();
    $component->assertCanSeeTableRecords([$future])
        ->assertTableActionDoesNotExist('edit', record: $future)
        ->assertTableActionDoesNotExist('delete', record: $future)
        ->assertTableActionVisible('annul', record: $future)
        ->assertTableActionVisible('replace', record: $future)
        ->callTableAction('annul', record: $future, data: ['reason' => 'Rinvio'])
        ->assertHasNoTableActionErrors();

    expect($future->refresh()->annulled_at)->not->toBeNull();
});

it('hides mutation actions from viewers and for effective transitions', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectTransitionResource($viewer, $company, false);
    $project = Project::factory()->for($company)->create();
    $effective = ProjectTransition::factory()->forProject($project)->create([
        'effective_date' => '2026-08-01',
        'created_by_id' => $viewer->id,
    ]);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ProjectTransitionsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertTableActionHidden('createTransition')
        ->assertTableActionHidden('annul', record: $effective)
        ->assertTableActionHidden('replace', record: $effective)
        ->assertTableActionDoesNotExist('delete', record: $effective);
});
