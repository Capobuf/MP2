<?php

use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalPurpose;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function approvedBudget(Company $company, Exercise $exercise, User $approver, int $version = 1): BudgetSnapshot
{
    $proposal = Proposal::factory()->for($company)->for($exercise)->for($approver, 'creator')->create();
    $proposal->update([
        'status' => 'approved',
        'approved_by_id' => $approver->id,
        'approved_at' => now(),
        'approval_operation_id' => (string) Str::uuid(),
    ]);

    return BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $approver->id,
        'version' => $version,
    ]);
}

it('starts an idempotent revision from live reality and references the latest budget without cloning it', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '10.00']);
    $budget = approvedBudget($company, $exercise, $actor);
    $line->update(['amount' => '15.00']);
    $operationId = (string) Str::uuid();

    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise, $operationId);
    $retry = app(InitializeProposal::class)->execute($actor, $company, $exercise, $operationId);

    expect($retry->is($revision))->toBeTrue()
        ->and($revision->purpose)->toBe(ProposalPurpose::Revision)
        ->and($revision->reference_budget_id)->toBe($budget->id)
        ->and(data_get($revision->items->sole()->baseline, 'plan_baseline.estimate_lines.0.amount'))->toBe('15.00')
        ->and($budget->fresh()->version)->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->sole()->eventType())->toBe(AuditEventType::ProposalRevisionInitialized);
});

it('uses the highest budget version as the only revision reference', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $v1 = approvedBudget($company, $exercise, $actor, 1);
    $v2Proposal = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create(['purpose' => 'revision', 'reference_budget_id' => $v1->id]);
    $v2Proposal->update(['status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid()]);
    $v2 = BudgetSnapshot::factory()->for($v2Proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'approved_by_id' => $actor->id, 'version' => 2, 'purpose' => 'revision', 'previous_budget_id' => $v1->id]);

    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    expect($revision->reference_budget_id)->toBe($v2->id);
});

it('rejects unauthorized foreign closed and concurrent revision creation without partial rows', function (): void {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $closed = Exercise::factory()->for($company)->create(['status' => 'closed']);
    $actor = User::factory()->create();
    $manager = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    approvedBudget($company, $exercise, $manager);
    approvedBudget($company, $closed, $manager);

    expect(fn () => app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(InitializeProposal::class)->execute($manager, $otherCompany, $exercise, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(InitializeProposal::class)->execute($manager, $company, $closed, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $revision = app(InitializeProposal::class)->execute($manager, $company, $exercise, (string) Str::uuid());
    expect(fn () => app(InitializeProposal::class)->execute($manager, $company, $exercise, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(Proposal::query()->where('exercise_id', $exercise->id)->where('status', 'draft')->sole()->is($revision))->toBeTrue();
});
