<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalPurpose;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function revisionApprovalFixture(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $initialProposal = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create();
    $initialProposal->update(['status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid()]);
    $v1 = BudgetSnapshot::factory()->for($initialProposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'approved_by_id' => $actor->id]);
    $revision = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    return compact('company', 'exercise', 'actor', 'v1', 'revision');
}

it('creates and retries exactly one immutable next budget version with predecessor and reason', function (): void {
    ['actor' => $actor, 'v1' => $v1, 'revision' => $revision] = revisionApprovalFixture();
    $operationId = (string) Str::uuid();

    $v2 = app(ApproveProposal::class)->execute($actor, $revision, $operationId, ['reason' => 'Adeguamento annuale']);
    $retry = app(ApproveProposal::class)->execute($actor, $revision->refresh(), $operationId, ['reason' => 'Adeguamento annuale']);

    expect($retry->is($v2))->toBeTrue()
        ->and($v2->version)->toBe(2)
        ->and($v2->purpose)->toBe(ProposalPurpose::Revision)
        ->and($v2->previous_budget_id)->toBe($v1->id)
        ->and($v1->fresh()->version)->toBe(1)
        ->and(BudgetSnapshot::query()->count())->toBe(2)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::BudgetRevisionCreated)->count())->toBe(1);
});

it('requires a revision reason and rejects a superseded predecessor before live effects', function (): void {
    ['company' => $company, 'exercise' => $exercise, 'actor' => $actor, 'v1' => $v1, 'revision' => $revision] = revisionApprovalFixture();

    expect(fn () => app(ApproveProposal::class)->execute($actor, $revision, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $otherProposal = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create([
        'purpose' => 'revision', 'status' => 'approved', 'reference_budget_id' => $v1->id,
        'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid(),
    ]);
    BudgetSnapshot::factory()->for($otherProposal)->create([
        'company_id' => $company->id, 'exercise_id' => $exercise->id, 'approved_by_id' => $actor->id,
        'version' => 2, 'purpose' => 'revision', 'previous_budget_id' => $v1->id,
    ]);

    expect(fn () => app(ApproveProposal::class)->execute($actor, $revision->refresh(), (string) Str::uuid(), ['reason' => 'Stale']))
        ->toThrow(ValidationException::class)
        ->and($revision->fresh()->status->value)->toBe('draft');
});

it('rolls back revision materialization failures without changing the predecessor', function (): void {
    ['actor' => $actor, 'v1' => $v1, 'revision' => $revision] = revisionApprovalFixture();

    expect(fn () => app(ApproveProposal::class)->execute($actor, $revision, (string) Str::uuid(), ['reason' => 'Revisione'], checkpoint: fn (string $stage) => $stage === 'after_budget_header' ? throw new RuntimeException('failure') : null))
        ->toThrow(RuntimeException::class)
        ->and(BudgetSnapshot::query()->count())->toBe(1)
        ->and($v1->fresh()->version)->toBe(1)
        ->and($revision->fresh()->status->value)->toBe('draft');
});
