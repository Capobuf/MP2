<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Exercises\Pages\CloseExercise as CloseExercisePage;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function s9UiUser(Company $company, array $capabilities): User
{
    $user = User::factory()->create();
    foreach ($capabilities as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }

    return $user;
}

it('shows the Closing action only to an authorized user and renders the transient Closing review', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $closer = s9UiUser($company, [Capability::View, Capability::CloseExercise]);
    $this->actingAs($closer);
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionVisible('closeExercise');

    Livewire::test(CloseExercisePage::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertSee('Chiusura Esercizio 2025')
        ->assertSee('L’Esercizio non potrà essere riaperto')
        ->set('closing.management_continues', false)
        ->call('reviewClosing')
        ->assertSee('Valori che verranno congelati')
        ->assertSee('Allocato')
        ->assertSee('Effettivo');
});

it('hides Closing mutation from a user who only manages ordinary operations', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $operator = s9UiUser($company, [Capability::View, Capability::ManageOperations]);
    $this->actingAs($operator);
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertActionHidden('closeExercise');
});

it('shows the immutable Closing Snapshot entry point on a Closed Exercise', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $closer = s9UiUser($company, [Capability::View, Capability::CloseExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    ClosingSnapshot::query()->create([
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
        'next_exercise_disposition' => 'not_created_management_terminated',
        'next_exercise_id' => null,
        'operation_id' => (string) Str::uuid(),
    ]);
    $exercise->update(['status' => 'closed']);
    $this->actingAs($closer);
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionVisible('viewClosing')
        ->assertActionHidden('closeExercise')
        ->assertActionHidden('initializeProposal')
        ->assertActionHidden('createExpense');
});
