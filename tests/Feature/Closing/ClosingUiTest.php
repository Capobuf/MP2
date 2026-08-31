<?php

use App\Filament\Resources\Closings\Pages\ViewClosing as ViewClosingPage;
use App\Filament\Resources\Exercises\Pages\CloseExercise as CloseExercisePage;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function s9UiUser(Company $company, array $capabilities): User
{
    $user = User::factory()->create();
    foreach ($capabilities as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
    }

    return $user;
}

it('shows the Closing action only to an authorized user and renders the transient Closing review', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $closer = s9UiUser($company, [TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE]);
    $this->actingAs($closer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionVisible('closeExercise');

    Livewire::test(CloseExercisePage::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertSee('Chiusura Esercizio 2025')
        ->assertSee('L’Esercizio non potrà essere riaperto')
        ->assertSee('Crea N+1')
        ->assertSee('Non creare N+1')
        ->set('closing.create_next_exercise', false)
        ->call('reviewClosing')
        ->assertSee('Valori che verranno congelati')
        ->assertSee('Allocato')
        ->assertSee('Effettivo');
});

it('hides Closing mutation from a user who only manages ordinary operations', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $operator = s9UiUser($company, [TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS]);
    $this->actingAs($operator);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertActionHidden('closeExercise');
});

it('shows the immutable Closing Snapshot entry point on a Closed Exercise', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $closer = s9UiUser($company, [TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $snapshot = ClosingSnapshot::query()->create([
        'company_id' => $company->id,
        'company_name' => $company->name,
        'exercise_id' => $exercise->id,
        'exercise_year' => 2025,
        'closed_at' => now(),
        'closed_by_id' => $closer->id,
        'initial_budget_id' => null,
        'current_budget_id' => null,
        'total_final_allocation' => '0.00',
        'total_closing_actual' => '0.00',
        'total_operational_variance' => '0.00',
        'total_consolidated_carryover' => '0.00',
        'accepted_warnings' => [],
        'applied_settings' => [
            'timezone' => $company->timezone,
            'overspend_note_required' => false,
            'unclassified_closing_policy' => $company->closingUnclassifiedPolicy()->value,
        ],
        'next_exercise_disposition' => 'not_created',
        'next_exercise_id' => null,
        'operation_id' => (string) Str::uuid(),
    ]);
    $exercise->update(['status' => 'closed']);
    $this->actingAs($closer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionVisible('viewClosing')
        ->assertActionHidden('closeExercise')
        ->assertActionHidden('initializeProposal')
        ->assertActionHidden('createExpense');

    Livewire::test(ViewClosingPage::class, ['record' => $snapshot->id])
        ->assertSuccessful()
        ->assertSee('Snapshot di Chiusura')
        ->assertSee('Assente')
        ->assertSee('Nessun avviso accettato.')
        ->assertSee('Europe/Rome');
});

it('keeps the reviewed fingerprint while acknowledging warnings and confirms Closing', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $closer = s9UiUser($company, [TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $costCenter = CostCenter::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $costCenter->id]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    $this->actingAs($closer);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(CloseExercisePage::class, ['record' => $exercise->id])
        ->set('closing.create_next_exercise', false)
        ->call('reviewClosing');
    $fingerprint = $component->get('reviewFingerprint');

    $component->set('closing.warnings_acknowledged', true)
        ->set('closing.confirmed', true);
    expect($fingerprint)->toBeString()
        ->and($component->get('reviewFingerprint'))->toBe($fingerprint);

    $component->call('closeExercise')->assertHasNoErrors();
    expect($exercise->refresh()->isOpen())->toBeFalse()
        ->and(ClosingSnapshot::query()->where('exercise_id', $exercise->id)->count())->toBe(1);
});

it('confirms a newly reviewed Closing-time Reprogramming without changing its fingerprint', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $closer = s9UiUser($company, [TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);
    $this->actingAs($closer);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(CloseExercisePage::class, ['record' => $exercise->id])
        ->set('closing.create_next_exercise', true)
        ->set("closing.projects.{$project->id}.mode", 'reprogramming')
        ->set("closing.projects.{$project->id}.reason", 'Riprogrammazione finale')
        ->set("closing.projects.{$project->id}.reductions.{$estimate->id}.selected", true)
        ->set("closing.projects.{$project->id}.reductions.{$estimate->id}.reduction_amount", '30.00')
        ->set("closing.projects.{$project->id}.reductions.{$estimate->id}.destination_supplier_id", 'none')
        ->call('reviewClosing')
        ->assertHasNoErrors();
    $fingerprint = $component->get('reviewFingerprint');

    $component
        ->set('closing.warnings_acknowledged', true)
        ->set('closing.confirmed', true)
        ->call('closeExercise')
        ->assertHasNoErrors();

    $destination = Exercise::query()
        ->where('company_id', $company->id)
        ->where('year', 2026)
        ->sole();

    expect($fingerprint)->toBeString()
        ->and($estimate->refresh()->amount)->toBe('70.00')
        ->and(ProjectDeferral::query()->where('project_id', $project->id)->sole()->reprogrammed_amount)->toBe('30.00')
        ->and(Expense::query()->where('project_id', $project->id)->where('exercise_id', $destination->id)->sole()->allocation())->toBe('30.00')
        ->and($exercise->refresh()->isOpen())->toBeFalse();
});
