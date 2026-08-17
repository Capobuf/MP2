<?php

use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('materializes exact stable expense and line values', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Licenze']);
    $line = ExpenseLine::factory()->for($expense)->create([
        'type' => ExpenseLineType::Estimate,
        'amount' => '10.50',
    ]);

    $snapshot = ExpenseAuditSnapshot::expense($expense, true);

    expect($snapshot['origin_key'])->toBe('expense:'.$expense->id)
        ->and($snapshot['allocation'])->toBe('10.50')
        ->and($snapshot['actual'])->toBe('0.00')
        ->and($snapshot['lines'][0])->toBe(ExpenseAuditSnapshot::line($line))
        ->and(ExpenseAuditSnapshot::impact($exercise->id, '-10.5'))
        ->toBe([(string) $exercise->id => '-10.50']);
});
