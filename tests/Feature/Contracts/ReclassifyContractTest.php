<?php

use App\Actions\Operations\CreateExercise;
use App\Actions\Operations\UpdateContractClassification;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function contractReclassificationContext(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create();
    $classification = ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();

    return compact('actor', 'company', 'exercise', 'contract', 'classification');
}

it('reclassifies the full annual Contract after exact preview and retries idempotently', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract, 'classification' => $classification] = contractReclassificationContext();
    $target = CostCenter::factory()->for($company)->create();
    $system = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'system', 'direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($system)->create(['amount' => '100.00']);
    $manual = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'manual', 'direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($manual)->actual()->create(['amount' => '75.00']);
    $action = app(UpdateContractClassification::class);
    $preview = $action->preview($actor, $contract, $exercise, $target->id);
    $operationId = (string) Str::uuid();

    expect(fn () => $action->confirm($actor, $contract, $preview, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    $updated = $action->confirm($actor, $contract, $preview, $operationId, 'Riclassificazione annuale');
    $retry = $action->confirm($actor, $contract, $preview, $operationId);
    $event = AuditEvent::query()->sole();

    expect($retry->is($updated))->toBeTrue()
        ->and($classification->refresh()->cost_center_id)->toBe($target->id)
        ->and($contract->refresh()->revision)->toBe(1)
        ->and($exercise->refresh()->revision)->toBe(1)
        ->and($event->event_type)->toBe(AuditEventType::ContractClassificationChanged)
        ->and($event->new_value['affected_expense_ids'])->toBe([$system->id, $manual->id])
        ->and($event->new_value['allocation_reclassified'])->toBe('100.00')
        ->and($event->new_value['actual_reclassified'])->toBe('75.00')
        ->and($event->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '0.00'])
        ->and($event->actual_impact_by_exercise)->toBe([(string) $exercise->id => '0.00']);
});

it('supports Unclassified and rejects stale archived cross-company and closed-year choices', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract, 'classification' => $classification] = contractReclassificationContext();
    $archived = CostCenter::factory()->for($company)->archived()->create();
    $other = CostCenter::factory()->create();
    $action = app(UpdateContractClassification::class);

    expect(fn () => $action->preview($actor, $contract, $exercise, $archived->id))->toThrow(ValidationException::class)
        ->and(fn () => $action->preview($actor, $contract, $exercise, $other->id))->toThrow(ValidationException::class);

    $preview = $action->preview($actor, $contract, $exercise, null);
    $contract->increment('revision');
    expect(fn () => $action->confirm($actor, $contract, $preview, (string) Str::uuid()))->toThrow(ValidationException::class);

    $preview = $action->preview($actor, $contract->refresh(), $exercise, null);
    $action->confirm($actor, $contract, $preview, (string) Str::uuid());
    expect($classification->refresh()->cost_center_id)->toBeNull();

    closeExerciseFixture($exercise, $actor);
    expect(fn () => $action->preview($actor, $contract, $exercise->refresh(), null))->toThrow(ValidationException::class);
});

it('rolls Contract classification revisions and Timeline back together', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract, 'classification' => $classification] = contractReclassificationContext();
    $target = CostCenter::factory()->for($company)->create();
    $preview = app(UpdateContractClassification::class)->preview($actor, $contract, $exercise, $target->id);
    AuditEvent::creating(fn () => throw new RuntimeException('Forced audit failure'));

    expect(fn () => app(UpdateContractClassification::class)->confirm($actor, $contract, $preview, (string) Str::uuid()))
        ->toThrow(RuntimeException::class)
        ->and($classification->refresh()->cost_center_id)->toBeNull()
        ->and($contract->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);
    AuditEvent::flushEventListeners();
});

it('seeds the latest nullable Contract classification into a new Exercise without values', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract] = contractReclassificationContext();
    $costCenter = CostCenter::factory()->for($company)->create();
    $contract->classifications()->where('exercise_id', $exercise->id)->update(['cost_center_id' => $costCenter->id]);

    $newExercise = app(CreateExercise::class)->execute($actor, $company, ['year' => 2027], (string) Str::uuid());
    $seeded = $contract->classifications()->where('exercise_id', $newExercise->id)->sole();

    expect($seeded->cost_center_id)->toBe($costCenter->id)
        ->and($contract->expenses()->where('exercise_id', $newExercise->id)->count())->toBe(0)
        ->and($newExercise->allocation())->toBe('0.00')
        ->and($newExercise->actual())->toBe('0.00');
});
