<?php

use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists the autonomous aggregate with stable identity and exact derived values', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();

    ExpenseLine::factory()->for($expense)->create(['type' => ExpenseLineType::Estimate, 'amount' => '1000.00']);
    ExpenseLine::factory()->for($expense)->create(['type' => ExpenseLineType::Estimate, 'amount' => '500.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-100.00', 'note' => 'Rimborso']);
    ExpenseLine::factory()->for($expense)->actual()->annulled()->create(['amount' => '999.00']);

    expect($exercise->status)->toBe(ExerciseStatus::Open)
        ->and($expense->originKey())->toBe('expense:'.$expense->id)
        ->and($expense->allocation())->toBe('1500.00')
        ->and($expense->actual())->toBe('0.00')
        ->and($expense->operationalVariance())->toBe('-1500.00')
        ->and($expense->hasActuals())->toBeTrue()
        ->and($exercise->allocation())->toBe('1500.00')
        ->and($exercise->actual())->toBe('0.00');
});

it('enforces company references and line checks in the database', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $exerciseA = Exercise::factory()->for($companyA)->create();
    $supplierB = Supplier::factory()->for($companyB)->create();
    $costCenterB = CostCenter::factory()->for($companyB)->create();

    expect(fn () => Expense::query()->create([
        'company_id' => $companyA->id,
        'exercise_id' => $exerciseA->id,
        'supplier_id' => $supplierB->id,
        'direct_cost_center_id' => null,
        'description' => 'Riferimento errato',
    ]))->toThrow(QueryException::class)
        ->and(fn () => Expense::query()->create([
            'company_id' => $companyA->id,
            'exercise_id' => $exerciseA->id,
            'supplier_id' => null,
            'direct_cost_center_id' => $costCenterB->id,
            'description' => 'Riferimento errato',
        ]))->toThrow(QueryException::class);

    $expense = Expense::factory()->forExercise($exerciseA)->create();

    expect(fn () => ExpenseLine::factory()->for($expense)->create([
        'type' => ExpenseLineType::Estimate,
        'amount' => '-0.01',
    ]))->toThrow(QueryException::class)
        ->and(fn () => ExpenseLine::factory()->for($expense)->actual()->create([
            'amount' => '-0.01',
            'note' => ' ',
        ]))->toThrow(QueryException::class);
});

it('rejects ordinary physical deletion', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create();

    expect(fn () => $line->delete())->toThrow(LogicException::class)
        ->and(fn () => $expense->delete())->toThrow(LogicException::class)
        ->and(fn () => $exercise->delete())->toThrow(LogicException::class);
});
