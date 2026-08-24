<?php

use App\Domain\LateCorrections\HistoricalExpenseCompatibility;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('accepts only a manual Expense in the exact historical owner and Exercise', function (): void {
    $rule = app(HistoricalExpenseCompatibility::class);
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['status' => 'closed']);
    $project = Project::factory()->for($company)->create();
    $otherExercise = Exercise::factory()->for($company)->create(['year' => $exercise->year + 1]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['origin' => 'manual']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);

    expect($rule->accepts($expense, $exercise, 'project', $project->id))->toBeTrue()
        ->and($rule->accepts($expense, $otherExercise, 'project', $project->id))->toBeFalse()
        ->and($rule->accepts($expense, $exercise, 'expense', $expense->id))->toBeFalse();
});

it('rejects system Estimates and reversed Expenses without matching another record', function (): void {
    $rule = app(HistoricalExpenseCompatibility::class);
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['status' => 'closed']);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create(['supplier_id' => $supplier->id]);
    $system = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'system']);
    ExpenseLine::factory()->for($system)->create(['type' => 'estimate']);
    $reversed = Expense::factory()->forExercise($exercise)->reversed()->create();

    expect($rule->accepts($system, $exercise, 'contract', $contract->id))->toBeFalse()
        ->and($rule->accepts($reversed, $exercise, 'expense', $reversed->id))->toBeFalse();
});

it('keeps an Archived historical Supplier compatible', function (): void {
    $rule = app(HistoricalExpenseCompatibility::class);
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['status' => 'closed']);
    $supplier = Supplier::factory()->for($company)->archived()->create();
    $expense = Expense::factory()->forExercise($exercise)->create(['supplier_id' => $supplier->id]);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);

    expect($supplier->isArchived())->toBeTrue()
        ->and($rule->accepts($expense, $exercise, 'expense', $expense->id))->toBeTrue();
});
