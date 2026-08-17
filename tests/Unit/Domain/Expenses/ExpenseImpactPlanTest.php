<?php

use App\Domain\Expenses\ExpenseImpactPlan;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shows exact source and destination before after and deltas', function () {
    $company = Company::factory()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2026, 'revision' => 2]);
    $target = Exercise::factory()->for($company)->create(['year' => 2027, 'revision' => 4]);
    $expense = Expense::factory()->forExercise($source)->create(['revision' => 3]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);

    $plan = ExpenseImpactPlan::build($expense, $source, $target, null, null, null);

    expect($plan->expenseRevision)->toBe(3)
        ->and($plan->exerciseRevisions)->toBe([(string) $source->id => 2, (string) $target->id => 4])
        ->and($plan->exerciseImpacts[(string) $source->id]['allocation_after'])->toBe('0.00')
        ->and($plan->exerciseImpacts[(string) $source->id]['allocation_delta'])->toBe('-100.00')
        ->and($plan->exerciseImpacts[(string) $target->id]['allocation_after'])->toBe('100.00')
        ->and($plan->allocatedImpact())->toBe([(string) $source->id => '-100.00', (string) $target->id => '100.00'])
        ->and(strlen($plan->fingerprint()))->toBe(64);
});
