<?php

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps S3 read and mutation authority to the exact company', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
    $exerciseA = Exercise::factory()->for($companyA)->create();
    $expenseA = Expense::factory()->forExercise($exerciseA)->create();
    $lineA = ExpenseLine::factory()->for($expenseA)->create();
    $exerciseB = Exercise::factory()->for($companyB)->create();
    $expenseB = Expense::factory()->forExercise($exerciseB)->create();

    expect($user->can('view', $exerciseA))->toBeTrue()
        ->and($user->can('update', $expenseA))->toBeTrue()
        ->and($user->can('update', $lineA))->toBeTrue()
        ->and($user->can('view', $exerciseB))->toBeFalse()
        ->and($user->can('update', $expenseB))->toBeFalse()
        ->and($user->can('delete', $exerciseA))->toBeFalse()
        ->and($user->can('delete', $expenseA))->toBeFalse()
        ->and($user->can('delete', $lineA))->toBeFalse();
});
