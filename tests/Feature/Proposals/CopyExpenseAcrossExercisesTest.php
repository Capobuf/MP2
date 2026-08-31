<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CopyExpenseIntoProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('copies estimates from a closed exercise into a new open-year identity without touching origin or actuals', function (): void {
    $company = Company::factory()->create();
    $closed = Exercise::factory()->for($company)->create(['year' => 2025, 'status' => 'closed']);
    $destination = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $source = Expense::factory()->forExercise($closed)->create(['description' => 'Origine chiusa']);
    $estimate = ExpenseLine::factory()->for($source)->create(['type' => 'estimate', 'amount' => '9.00']);
    $actual = ExpenseLine::factory()->for($source)->create(['type' => 'actual', 'amount' => '4.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());

    app(CopyExpenseIntoProposal::class)->execute($actor, $proposal, $source, (string) Str::uuid(), 0);
    app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    $copy = Expense::query()->where('exercise_id', $destination->id)->sole();
    expect($copy->id)->not->toBe($source->id)
        ->and($copy->copied_from_origin_key)->toBe($source->originKey())
        ->and($copy->allocation())->toBe('9.00')
        ->and($copy->actual())->toBe('0.00')
        ->and($estimate->fresh()->amount)->toBe('9.00')
        ->and($actual->fresh()->amount)->toBe('4.00')
        ->and($closed->fresh()->revision)->toBe(0);
});

it('blocks approval when the copied source changes after planning', function (): void {
    $company = Company::factory()->create();
    $sourceExercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $source = Expense::factory()->forExercise($sourceExercise)->create();
    $line = ExpenseLine::factory()->for($source)->create(['type' => 'estimate', 'amount' => '9.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    app(CopyExpenseIntoProposal::class)->execute($actor, $proposal, $source, (string) Str::uuid(), 0);
    $line->update(['amount' => '10.00']);

    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(Expense::query()->where('exercise_id', $destination->id)->count())->toBe(0);
});
