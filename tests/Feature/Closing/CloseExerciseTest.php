<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function s9CloseActor(Company $company): User
{
    $user = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }

    return $user;
}

function s9CarryoverProject(Company $company, Exercise $exercise): Project
{
    $project = Project::factory()->for($company)->create([
        'title' => 'Carryover at Closing',
        'initial_state' => 'open',
        'initial_effective_date' => $exercise->year.'-01-01',
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['description' => 'Closing source plan']);
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);

    return $project;
}

function s9PreparedCarryover(User $actor, Exercise $exercise, Project $project): array
{
    return app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'management_continues' => true,
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '50.00',
            'reason' => 'Riporto deciso alla Chiusura',
        ]],
    ]);
}

it('closes without a Budget, creates N+1 and consolidates Carryover exactly once', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create(['name' => 'S9 Closing Company']);
    $actor = s9CloseActor($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $project = s9CarryoverProject($company, $exercise);
    $prepared = s9PreparedCarryover($actor, $exercise, $project);
    $operationId = (string) Str::uuid();

    $input = [...$prepared['input'], 'review_fingerprint' => $prepared['execution_fingerprint'], 'warnings_acknowledged' => true, 'confirmed' => true];
    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, $input, $operationId);
    $next = Exercise::query()->where('company_id', $company->id)->where('year', 2026)->sole();
    $deferral = ProjectDeferral::query()->where('project_id', $project->id)->where('source_exercise_id', $exercise->id)->sole();
    $projectRow = $snapshot->rows()->where('origin_key', $project->originKey())->sole();

    expect($exercise->refresh()->isOpen())->toBeFalse()
        ->and($snapshot->initial_budget_id)->toBeNull()
        ->and($snapshot->current_budget_id)->toBeNull()
        ->and($snapshot->next_exercise_disposition)->toBe('created')
        ->and($snapshot->next_exercise_id)->toBe($next->id)
        ->and($snapshot->total_final_allocation)->toBe('100.00')
        ->and($snapshot->total_closing_actual)->toBe('40.00')
        ->and($snapshot->total_consolidated_carryover)->toBe('50.00')
        ->and($deferral->carryover_state)->toBe('consolidated')
        ->and($deferral->carryover_amount)->toBe('50.00')
        ->and($next->allocation())->toBe('50.00')
        ->and($projectRow->final_allocation)->toBe('100.00');

    $retry = app(CloseExercise::class)->execute($actor, $exercise->refresh(), $input, $operationId);
    expect($retry->id)->toBe($snapshot->id)
        ->and(ClosingSnapshot::query()->where('exercise_id', $exercise->id)->count())->toBe(1)
        ->and(Exercise::query()->where('company_id', $company->id)->where('year', 2026)->count())->toBe(1);
});

it('records intentional non-creation of N+1 when management is terminated', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = s9CloseActor($company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['management_continues' => false, 'projects' => []]);

    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'confirmed' => true,
        'warnings_acknowledged' => false,
    ], (string) Str::uuid());

    expect($snapshot->next_exercise_disposition)->toBe('not_created_management_terminated')
        ->and($snapshot->next_exercise_id)->toBeNull()
        ->and(Exercise::query()->where('company_id', $company->id)->where('year', 2026)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::NextExerciseNotCreated->value)->exists())->toBeTrue();
});
