<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\UpdateExpense;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('filters immutable Project history even after an Expense leaves the Project', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    $other = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '5.00'], (string) Str::uuid());
    $move = app(UpdateExpense::class)->preview($actor, $expense->refresh(), [
        'project_id' => $other->id,
        'reason' => 'Correzione contenitore',
    ]);
    app(UpdateExpense::class)->confirm($actor, $expense, $move, (string) Str::uuid());
    $projectEvents = AuditEvent::query()->forProject($project)->orderByDesc('id')->get();
    $moveEvent = AuditEvent::query()->where('event_type', 'expense_moved_or_reclassified')->sole();
    $this->actingAs($actor);
    Filament::setTenant($company);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHasUrl('timeline', CompanyAudit::getUrl([
            'tenant' => $company,
            'project' => $project->id,
        ]));

    $timeline = Livewire::withQueryParams(['project' => $project->id])
        ->test(CompanyAudit::class)
        ->assertCanSeeTableRecords($projectEvents, inOrder: true)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    expect($expense->refresh()->project_id)->toBe($other->id)
        ->and($moveEvent->reference_id)->toBe($other->id)
        ->and($projectEvents->modelKeys())->toContain($moveEvent->id);
});

it('shows Project values references overspend and operation identity in Italian', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '3.00'], (string) Str::uuid());
    $event = AuditEvent::query()->sole();
    $this->actingAs($actor);
    Filament::setTenant($company);

    Livewire::withQueryParams(['project' => $project->id])
        ->test(CompanyAudit::class)
        ->assertTableActionExists('details', record: $event)
        ->assertTableColumnStateSet('overspend', 'Sovraspesa creata: € 0.00 → € 3.00', $event)
        ->assertTableColumnStateSet('reference', 'Progetto #'.$project->id, $event)
        ->assertTableColumnStateSet('operation_id', $event->operation_id, $event);
});
