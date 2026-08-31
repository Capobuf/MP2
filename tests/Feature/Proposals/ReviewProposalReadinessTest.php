<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Proposals\ProposalReadinessState;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('adds newly qualifying sources for review and marks changed baselines for realignment', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $first = Expense::factory()->forExercise($exercise)->create();
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $first->increment('revision');
    $second = Expense::factory()->forExercise($exercise)->create();
    $reviewed = app(ReviewProposalReadiness::class)->execute($user, $proposal, (string) Str::uuid());
    expect($reviewed->items()->where('expense_id', $first->id)->sole()->readiness_state)->toBe(ProposalReadinessState::ToRealign)
        ->and($reviewed->items()->where('expense_id', $second->id)->sole()->readiness_state)->toBe(ProposalReadinessState::ToReview);
});
