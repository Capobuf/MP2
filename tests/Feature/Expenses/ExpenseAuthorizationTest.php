<?php

use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('maps S3 read and mutation authority to the exact company', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $companyA->id,
            'user' => $user,
            'permissions' => $capability,
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
