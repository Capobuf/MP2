<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\UpdateExpense;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function s10ExcludedFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS, TestPermissions::CORRECT_CLOSED_EXERCISE] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $actor,
            'permissions' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $nextExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_effective_date' => '2025-01-01']);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    $deferral = ProjectDeferral::factory()
        ->for($project)
        ->for($exercise, 'sourceExercise')
        ->for($nextExercise, 'destinationExercise')
        ->carryover('10.00')
        ->state(['carryover_state' => 'consolidated'])
        ->create(['company_id' => $company->id]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create([
        'status' => 'approved',
        'approved_by_id' => $actor->id,
        'approved_at' => now(),
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
    ]);
    $snapshot = closeExerciseFixture($exercise, $actor);

    return compact('company', 'actor', 'exercise', 'expense', 'deferral', 'budget', 'snapshot');
}

it('rejects ordinary Closed-year mutation, reclassification and reopening', function (): void {
    $fixture = s10ExcludedFixture();
    $lineCount = $fixture['expense']->lines()->count();
    $supplier = Supplier::factory()->for($fixture['company'])->create();

    expect(fn () => app(CreateExpenseLine::class)->execute(
        $fixture['actor'],
        $fixture['expense'],
        ['type' => 'actual', 'amount' => '1.00'],
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateExpense::class)->preview(
            $fixture['actor'],
            $fixture['expense'],
            ['supplier_id' => $supplier->id, 'reason' => 'Riclassificazione vietata'],
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => $fixture['exercise']->update(['status' => 'open']))->toThrow(LogicException::class)
        ->and($fixture['expense']->refresh()->lines()->count())->toBe($lineCount)
        ->and($fixture['expense']->supplier_id)->toBeNull()
        ->and($fixture['exercise']->refresh()->status()->value)->toBe('closed');
});

it('rejects recalculation or mutation of historical Snapshot, Budget and Carryover', function (): void {
    $fixture = s10ExcludedFixture();

    expect(fn () => $fixture['snapshot']->update(['total_closing_actual' => '101.00']))->toThrow(LogicException::class)
        ->and(fn () => $fixture['budget']->update(['total_approved_allocation' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => $fixture['deferral']->update(['carryover_amount' => '9.00']))->toThrow(ValidationException::class)
        ->and($fixture['snapshot']->refresh()->total_closing_actual)->toBe('100.00')
        ->and($fixture['deferral']->refresh()->carryover_amount)->toBe('10.00');
});

it('does not expose matching, reports, exports or other S11 controls in S10', function (): void {
    $fixture = s10ExcludedFixture();
    $this->actingAs($fixture['actor']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($fixture['company'])->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $fixture['exercise']->id])
        ->assertSuccessful()
        ->assertDontSee('Matching')
        ->assertDontSee('Previsto')
        ->assertDontSee('Non previsto')
        ->assertDontSee('Esporta')
        ->assertDontSee('Ricostruisci al');
});
