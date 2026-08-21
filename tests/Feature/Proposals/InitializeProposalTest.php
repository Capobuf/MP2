<?php

use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('initializes one isolated exact draft and retries by operation identity', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '5.00']);
    $operation = (string) Str::uuid();

    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, $operation);
    $retry = app(InitializeProposal::class)->execute($actor, $company, $exercise, $operation);

    expect($retry->is($proposal))->toBeTrue()
        ->and($proposal->items)->toHaveCount(1)
        ->and($proposal->items->first()->result)->not->toHaveKey('actual_context')
        ->and($expense->refresh()->revision)->toBe(0);

    expect(fn () => app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

it('includes reversed autonomous Expenses only when they still own plan or real lines', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $active = Expense::factory()->forExercise($exercise)->create();
    $reversedWithEstimate = Expense::factory()->forExercise($exercise)->create(['reversed_at' => now()]);
    $reversedWithRealLine = Expense::factory()->forExercise($exercise)->create(['reversed_at' => now()]);
    $reversedEmpty = Expense::factory()->forExercise($exercise)->create(['reversed_at' => now()]);
    ExpenseLine::factory()->for($reversedWithEstimate)->create(['type' => 'estimate', 'amount' => '5.00']);
    ExpenseLine::factory()->for($reversedWithRealLine)->create(['type' => 'actual', 'amount' => '2.00']);

    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    expect($proposal->items->pluck('expense_id')->sort()->values()->all())->toBe(collect([$active->id, $reversedWithEstimate->id, $reversedWithRealLine->id])->sort()->values()->all())
        ->and($proposal->items->whereIn('expense_id', [$reversedWithEstimate->id, $reversedWithRealLine->id])->every(fn ($item): bool => $item->read_only_source))->toBeTrue()
        ->and($proposal->items->contains('expense_id', $reversedEmpty->id))->toBeFalse();
});
