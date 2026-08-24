<?php

use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function s9ClosingUser(Company $company, array $capabilities): User
{
    $user = User::factory()->create();
    foreach ($capabilities as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }

    return $user;
}

function s9OpenProject(Company $company, Exercise $exercise, string $estimate = '100.00', string $actual = '40.00'): Project
{
    $project = Project::factory()->for($company)->create([
        'title' => 'Closing Project',
        'initial_state' => 'open',
        'initial_effective_date' => $exercise->year.'-01-01',
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['description' => 'Closing plan']);
    ExpenseLine::factory()->for($expense)->create(['amount' => $estimate]);
    if ($actual !== '0.00') {
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => $actual]);
    }

    return $project;
}

it('requires the CloseExercise capability independently from ordinary operations', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $operationsOnly = s9ClosingUser($company, [Capability::View, Capability::ManageOperations]);
    $closingOnly = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $otherExercise = Exercise::factory()->create(['year' => 2025]);

    expect(fn () => app(PrepareExerciseClosing::class)->execute($operationsOnly, $exercise, ['management_continues' => false, 'projects' => []]))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(PrepareExerciseClosing::class)->execute($closingOnly, $otherExercise, ['management_continues' => false, 'projects' => []]))
        ->toThrow(AuthorizationException::class);

    $prepared = app(PrepareExerciseClosing::class)->execute($closingOnly, $exercise, ['management_continues' => false, 'projects' => []]);

    expect($prepared['review']->canClose())->toBeTrue();
});

it('blocks Closing before the calendar year is over and when a previous Exercise is Open', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $current = Exercise::factory()->for($company)->create(['year' => 2026]);

    $currentReview = app(PrepareExerciseClosing::class)->execute($actor, $current, ['management_continues' => false, 'projects' => []])['review'];
    expect(collect($currentReview->blocks)->pluck('code'))->toContain('exercise_year_not_finished');

    $target = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2024]);
    $review = app(PrepareExerciseClosing::class)->execute($actor, $target, ['management_continues' => false, 'projects' => []])['review'];

    expect(collect($review->blocks)->pluck('code'))->toContain('previous_exercise_open');
});

it('blocks a same-year Draft but does not require an approved Budget', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    Proposal::factory()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'created_by_id' => $actor->id,
        'status' => 'draft',
    ]);

    $review = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['management_continues' => false, 'projects' => []])['review'];
    expect(collect($review->blocks)->pluck('code'))->toContain('draft_proposal_open')
        ->and($review->budget['approved_budget_absent'])->toBeTrue();
});

it('requires explicit Project state and deferral decisions and caps final Carryover', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = s9OpenProject($company, $exercise, '100.00', '40.00');

    $missing = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['projects' => []])['review'];
    expect(collect($missing->blocks)->pluck('code'))->toContain('project_decision_required');

    $overLimit = app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'projects' => [
            $project->id => [
                'project_id' => $project->id,
                'final_state' => 'open',
                'mode' => 'carryover',
                'carryover_amount' => '60.01',
                'reason' => 'Riporto esplicito',
            ],
        ],
    ])['review'];

    expect(collect($overLimit->blocks)->pluck('code'))->toContain('carryover_above_limit');

    $valid = app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'projects' => [
            $project->id => [
                'project_id' => $project->id,
                'final_state' => 'open',
                'mode' => 'carryover',
                'carryover_amount' => '50.00',
                'reason' => 'Riporto esplicito',
            ],
        ],
    ])['review'];

    expect(collect($valid->blocks)->pluck('code'))->not->toContain('carryover_above_limit')
        ->and($valid->projectDecisions[0]['maximum_transferable'])->toBe('60.00');
});

it('enumerates future Open Exercises changed only by a Project state decision', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $future = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);

    $review = app(PrepareExerciseClosing::class)->execute($actor, $source, [
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'closed',
            'mode' => 'none',
            'reason' => 'Chiusura definitiva al 31 dicembre',
        ]],
    ])['review'];
    $futureImpact = collect($review->affectedExercises)->firstWhere('exercise_id', $future->id);

    expect($futureImpact)->not->toBeNull()
        ->and($futureImpact['allocation_delta'])->toBe('0.00')
        ->and($futureImpact['state_changed'])->toBeTrue();
});

it('exposes canonical non-blocking warnings without invoice inference', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'planned',
        'initial_effective_date' => '2025-01-01',
    ]);
    Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
    ]);

    $review = app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'planned',
            'mode' => 'none',
        ]],
    ])['review'];
    $warningCodes = collect($review->warnings)->pluck('code');
    $warningText = collect($review->warnings)->pluck('message')->implode(' ');

    expect($warningCodes)->toContain(
        'allocated_without_actuals',
        'unclassified_source',
        'planned_project_never_opened',
        'contract_without_applicable_condition',
    )->and(strtolower($warningText))->not->toContain('fattura')
        ->not->toContain('pagamento');
});

it('turns missing first-level classification into a block under Company policy', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create(['unclassified_closing_policy' => 'blocking']);
    $actor = s9ClosingUser($company, [Capability::View, Capability::CloseExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);

    $review = app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'management_continues' => false,
        'projects' => [],
    ])['review'];

    expect(collect($review->blocks)->pluck('code'))->toContain('unclassified_source');
});
