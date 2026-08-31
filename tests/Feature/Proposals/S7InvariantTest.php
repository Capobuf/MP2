<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CopyExpenseIntoProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function s7Actor(Company $company): User
{
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }

    return $actor;
}

it('maps invariant 28.18: every open exercise can create an immutable next revision', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = s7Actor($company);
    $initial = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create();
    $initial->update(['status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid()]);
    $v1 = BudgetSnapshot::factory()->for($initial)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'approved_by_id' => $actor->id]);
    $v1Snapshot = $v1->fresh()->getRawOriginal();

    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $v2 = app(ApproveProposal::class)->execute($actor, $revision, (string) Str::uuid(), ['reason' => 'Revisione annuale']);

    expect($v2->version)->toBe(2)
        ->and($v2->previous_budget_id)->toBe($v1->id)
        ->and($v1->fresh()->getRawOriginal())->toBe($v1Snapshot);
});

it('maps invariant 28.22: source invalidation requires one whole-source realignment', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = s7Actor($company);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $line->update(['amount' => '6.00']);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $item = $proposal->items->sole();

    expect($item->readiness_state->value)->toBe('to_realign');
    $aligned = app(RealignProposalItem::class)->execute($actor, $proposal, $item, ProposalRealignmentChoice::Reload, null, [], (string) Str::uuid(), $proposal->revision);

    expect($aligned->readiness_state->value)->toBe('aligned')
        ->and(data_get($aligned->baseline, 'plan_baseline.estimate_lines.0.amount'))->toBe('6.00')
        ->and(data_get($aligned->result, 'estimate_lines.0.amount'))->toBe('6.00');
});

it('maps invariant 28.55: cross-exercise copy creates new identity lineage and no actuals', function (): void {
    $company = Company::factory()->create();
    $closed = Exercise::factory()->for($company)->create(['year' => 2025, 'status' => 'closed']);
    $open = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = s7Actor($company);
    $source = Expense::factory()->forExercise($closed)->create();
    ExpenseLine::factory()->for($source)->create(['type' => 'estimate', 'amount' => '5.00']);
    ExpenseLine::factory()->for($source)->create(['type' => 'actual', 'amount' => '3.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $open, (string) Str::uuid());
    app(CopyExpenseIntoProposal::class)->execute($actor, $proposal, $source, (string) Str::uuid(), 0);
    app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    $copy = Expense::query()->where('exercise_id', $open->id)->sole();
    expect($copy->id)->not->toBe($source->id)
        ->and($copy->copied_from_origin_key)->toBe($source->originKey())
        ->and($copy->actual())->toBe('0.00')
        ->and($source->fresh()->actual())->toBe('3.00');
});
