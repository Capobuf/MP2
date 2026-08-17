<?php

use App\Actions\Operations\CreateExpense;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function grantExpenseCreation(User $user, Company $company): void
{
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates expense and initial lines atomically with exact event and idempotent retry', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantExpenseCreation($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year]);
    $supplier = Supplier::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($company)->create();
    $operationId = (string) Str::uuid();
    $input = [
        'exercise_id' => $exercise->id,
        'supplier_id' => $supplier->id,
        'direct_cost_center_id' => $costCenter->id,
        'description' => ' Licenze laboratorio ',
        'lines' => [
            ['type' => 'estimate', 'amount' => '1000.00'],
            ['type' => 'actual', 'amount' => '900.00'],
        ],
    ];

    $expense = app(CreateExpense::class)->execute($actor, $company, $input, $operationId);
    $retry = app(CreateExpense::class)->execute($actor, $company, $input, $operationId);
    $event = AuditEvent::query()->sole();

    expect($retry->is($expense))->toBeTrue()
        ->and($expense->description)->toBe('Licenze laboratorio')
        ->and($expense->lines()->count())->toBe(2)
        ->and(Expense::query()->count())->toBe(1)
        ->and(ExpenseLine::query()->count())->toBe(2)
        ->and($expense->allocation())->toBe('1000.00')
        ->and($expense->actual())->toBe('900.00')
        ->and($event->event_type)->toBe(AuditEventType::ExpenseCreated)
        ->and($event->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '1000.00'])
        ->and($event->actual_impact_by_exercise)->toBe([(string) $exercise->id => '900.00']);
});

it('rejects missing lines, future actuals, archived and cross-company references without partial state', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $companyB = Company::factory()->create();
    grantExpenseCreation($actor, $companyA);
    $future = Exercise::factory()->for($companyA)->create(['year' => now('Europe/Rome')->year + 1]);
    $supplierB = Supplier::factory()->for($companyB)->create();
    $archived = CostCenter::factory()->for($companyA)->archived()->create();

    $attempt = fn (array $overrides) => app(CreateExpense::class)->execute($actor, $companyA, [
        'exercise_id' => $future->id,
        'description' => 'Non valida',
        'lines' => [['type' => 'estimate', 'amount' => '1.00']],
        ...$overrides,
    ], (string) Str::uuid());

    expect(fn () => $attempt(['lines' => []]))->toThrow(ValidationException::class)
        ->and(fn () => $attempt(['lines' => [['type' => 'actual', 'amount' => '1.00']]]))->toThrow(ValidationException::class)
        ->and(fn () => $attempt(['supplier_id' => $supplierB->id]))->toThrow(ValidationException::class)
        ->and(fn () => $attempt(['direct_cost_center_id' => $archived->id]))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rolls the entire aggregate back when event persistence fails', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseCreation($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    AuditEvent::creating(function (): never {
        throw new RuntimeException('Forced audit failure');
    });

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'description' => 'Rollback',
        'lines' => [['type' => 'estimate', 'amount' => '1.00']],
    ], (string) Str::uuid()))->toThrow(RuntimeException::class, 'Forced audit failure');

    expect(Expense::query()->count())->toBe(0)
        ->and(ExpenseLine::query()->count())->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);

    AuditEvent::flushEventListeners();
});
