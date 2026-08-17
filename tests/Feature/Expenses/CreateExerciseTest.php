<?php

use App\Actions\Operations\CreateExercise;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExerciseStatus;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function grantExerciseOperations(User $user, Company $company): void
{
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates an open exercise with one complete event and retries idempotently', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantExerciseOperations($actor, $company);
    $operationId = (string) Str::uuid();

    $exercise = app(CreateExercise::class)->execute($actor, $company, ['year' => 2030], $operationId);
    $retry = app(CreateExercise::class)->execute($actor, $company, ['year' => 2030], $operationId);
    $event = AuditEvent::query()->sole();

    expect($retry->is($exercise))->toBeTrue()
        ->and($exercise->status)->toBe(ExerciseStatus::Open)
        ->and(Exercise::query()->count())->toBe(1)
        ->and($event->event_type)->toBe(AuditEventType::ExerciseCreated)
        ->and($event->affected_exercise_ids)->toBe([$exercise->id])
        ->and($event->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '0.00'])
        ->and($event->actual_impact_by_exercise)->toBe([(string) $exercise->id => '0.00']);
});

it('allows multiple open years and same year in different companies but rejects a company duplicate', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantExerciseOperations($actor, $companyA);
    grantExerciseOperations($actor, $companyB);

    app(CreateExercise::class)->execute($actor, $companyA, ['year' => 2026], (string) Str::uuid());
    app(CreateExercise::class)->execute($actor, $companyA, ['year' => 2027], (string) Str::uuid());
    app(CreateExercise::class)->execute($actor, $companyB, ['year' => 2026], (string) Str::uuid());

    expect(fn () => app(CreateExercise::class)->execute($actor, $companyA, ['year' => 2026], (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(Exercise::query()->count())->toBe(3)
        ->and(AuditEvent::query()->count())->toBe(3);
});

it('rejects unauthorized creation without state or event', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();

    expect(fn () => app(CreateExercise::class)->execute($actor, $company, ['year' => 2026], (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(Exercise::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});
