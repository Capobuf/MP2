<?php

use App\Actions\Operations\UpdateProjectClassification;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function reclassificationContext(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create();
    $classification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();

    return [$actor, $company, $exercise, $project, $classification];
}

it('reclassifies one complete annual Project exactly and retries idempotently', function () {
    [$actor, $company, $exercise, $project, $classification] = reclassificationContext();
    $target = CostCenter::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '75.00']);
    $action = app(UpdateProjectClassification::class);
    $preview = $action->preview($actor, $project, $exercise, $target->id);
    $operationId = (string) Str::uuid();

    expect(fn () => $action->confirm($actor, $project, $preview, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    $updated = $action->confirm($actor, $project, $preview, $operationId, 'Riclassificazione annuale');
    $retry = $action->confirm($actor, $project, $preview, $operationId);
    $event = AuditEvent::query()->sole();

    expect($retry->is($updated))->toBeTrue()
        ->and($classification->refresh()->cost_center_id)->toBe($target->id)
        ->and($expense->refresh()->direct_cost_center_id)->toBeNull()
        ->and($project->refresh()->annualTotals()[$exercise->id]['allocation'])->toBe('100.00')
        ->and($project->revision)->toBe(1)
        ->and($exercise->refresh()->revision)->toBe(1)
        ->and($event->event_type)->toBe(AuditEventType::ProjectClassificationChanged)
        ->and($event->new_value['allocation_reclassified'])->toBe('100.00')
        ->and($event->new_value['actual_reclassified'])->toBe('75.00');
});

it('supports Unclassified and rejects stale archived cross-company and closed-year requests', function () {
    [$actor, $company, $exercise, $project, $classification] = reclassificationContext();
    $archived = CostCenter::factory()->for($company)->archived()->create();
    $other = CostCenter::factory()->create();
    $action = app(UpdateProjectClassification::class);

    expect(fn () => $action->preview($actor, $project, $exercise, $archived->id))->toThrow(ValidationException::class)
        ->and(fn () => $action->preview($actor, $project, $exercise, $other->id))->toThrow(ValidationException::class);

    $preview = $action->preview($actor, $project, $exercise, null);
    $project->increment('revision');
    expect(fn () => $action->confirm($actor, $project, $preview, (string) Str::uuid()))->toThrow(ValidationException::class);

    $project->refresh();
    $preview = $action->preview($actor, $project, $exercise, null);
    $action->confirm($actor, $project, $preview, (string) Str::uuid());
    expect($classification->refresh()->cost_center_id)->toBeNull();

    closeExerciseFixture($exercise, $actor);
    expect(fn () => $action->preview($actor, $project, $exercise->refresh(), null))->toThrow(ValidationException::class);
});

it('rolls classification revisions and audit back together', function () {
    [$actor, $company, $exercise, $project, $classification] = reclassificationContext();
    $target = CostCenter::factory()->for($company)->create();
    $preview = app(UpdateProjectClassification::class)->preview($actor, $project, $exercise, $target->id);
    AuditEvent::creating(fn () => throw new RuntimeException('Forced audit failure'));

    expect(fn () => app(UpdateProjectClassification::class)->confirm($actor, $project, $preview, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);

    expect($classification->refresh()->cost_center_id)->toBeNull()
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);
    AuditEvent::flushEventListeners();
});
