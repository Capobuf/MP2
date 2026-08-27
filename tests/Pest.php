<?php

use App\Domain\Company\Capability;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExerciseStatus;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function s11ReportingViewer(Company $company): User
{
    $user = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'capability' => Capability::View,
    ]);

    return $user;
}

function closeExerciseFixture(Exercise $exercise, User $actor): ClosingSnapshot
{
    $exercise->loadMissing('company');
    $budgets = BudgetSnapshot::query()
        ->where('company_id', $exercise->company_id)
        ->where('exercise_id', $exercise->id)
        ->orderBy('version')
        ->get();
    $nextExercise = Exercise::query()
        ->where('company_id', $exercise->company_id)
        ->where('year', $exercise->year + 1)
        ->first();
    $carryover = Decimal::sum(
        ProjectDeferral::query()
            ->where('source_exercise_id', $exercise->id)
            ->where('mode', 'carryover')
            ->where('carryover_state', 'consolidated')
            ->pluck('carryover_amount'),
    );
    $snapshot = ClosingSnapshot::query()->create([
        'company_id' => $exercise->company_id,
        'company_name' => $exercise->company->name,
        'exercise_id' => $exercise->id,
        'exercise_year' => $exercise->year,
        'closed_at' => now(),
        'closed_by_id' => $actor->id,
        'initial_budget_id' => $budgets->first()?->id,
        'current_budget_id' => $budgets->last()?->id,
        'total_final_allocation' => $exercise->allocation(),
        'total_closing_actual' => $exercise->actual(),
        'total_operational_variance' => $exercise->operationalVariance(),
        'total_consolidated_carryover' => $carryover,
        'accepted_warnings' => [],
        'applied_settings' => [
            'timezone' => $exercise->company->timezone,
            'overspend_note_required' => (bool) $exercise->company->overspend_note_required,
            'unclassified_closing_policy' => $exercise->company->closingUnclassifiedPolicy()->value,
        ],
        'next_exercise_disposition' => $nextExercise === null
            ? 'not_created'
            : 'already_existed',
        'next_exercise_id' => $nextExercise?->id,
        'operation_id' => (string) Str::uuid(),
    ]);
    $exercise->update(['status' => ExerciseStatus::Closed]);

    return $snapshot;
}
