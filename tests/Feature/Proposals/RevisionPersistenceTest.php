<?php

use App\Domain\Proposals\ProposalActionStatus;
use App\Domain\Proposals\ProposalPurpose;
use App\Models\BudgetSnapshot;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists revision and discard lineage', function (): void {
    $initial = BudgetSnapshot::factory()->create();
    $initial->proposal->update([
        'status' => 'approved',
        'approved_by_id' => $initial->approved_by_id,
        'approved_at' => $initial->approved_at,
        'approval_operation_id' => (string) Str::uuid(),
    ]);
    $revision = Proposal::factory()->create([
        'company_id' => $initial->company_id,
        'exercise_id' => $initial->exercise_id,
        'purpose' => ProposalPurpose::Revision,
        'reference_budget_id' => $initial->id,
    ]);

    expect($revision->purpose)->toBe(ProposalPurpose::Revision)
        ->and($revision->referenceBudget->is($initial))->toBeTrue();

    $discarder = User::factory()->create();
    $operationId = (string) Str::uuid();
    $revision->update([
        'status' => 'discarded',
        'discarded_by_id' => $discarder->id,
        'discarded_at' => now(),
        'discard_reason' => 'Non più necessaria',
        'discard_operation_id' => $operationId,
    ]);

    expect($revision->fresh())
        ->discard_reason->toBe('Non più necessaria')
        ->discard_operation_id->toBe($operationId)
        ->discarder->is($discarder)->toBeTrue();
});

it('withdraws proposal actions only once without rewriting their decision', function (): void {
    $action = ProposalAction::factory()->create([
        'payload' => ['amount' => '10.00'],
        'reason' => 'Decisione originale',
    ]);
    $actor = User::factory()->create();
    $operationId = (string) Str::uuid();

    $action->update([
        'status' => ProposalActionStatus::Withdrawn,
        'withdrawn_by_id' => $actor->id,
        'withdrawn_at' => now(),
        'withdraw_operation_id' => $operationId,
        'withdraw_reason' => 'Ricarica realtà',
    ]);

    $withdrawn = $action->fresh();

    expect($withdrawn->status)->toBe(ProposalActionStatus::Withdrawn)
        ->and($withdrawn->payload)->toBe(['amount' => '10.00'])
        ->and($withdrawn->reason)->toBe('Decisione originale')
        ->and($withdrawn->withdrawer->is($actor))->toBeTrue()
        ->and(fn () => $withdrawn->update(['withdraw_reason' => 'Riscritta']))
        ->toThrow(LogicException::class)
        ->and(fn () => ProposalAction::factory()->create(['status' => ProposalActionStatus::Withdrawn]))
        ->toThrow(LogicException::class);
});

it('exposes active actions separately from immutable action history', function (): void {
    $proposal = Proposal::factory()->create();
    ProposalAction::factory()->create(['proposal_id' => $proposal->id, 'company_id' => $proposal->company_id, 'sequence' => 1]);
    $withdrawn = ProposalAction::factory()->create(['proposal_id' => $proposal->id, 'company_id' => $proposal->company_id, 'sequence' => 2]);
    $withdrawn->update([
        'status' => ProposalActionStatus::Withdrawn,
        'withdrawn_by_id' => User::factory()->create()->id,
        'withdrawn_at' => now(),
        'withdraw_operation_id' => (string) Str::uuid(),
        'withdraw_reason' => 'Rimossa',
    ]);

    expect($proposal->actions()->pluck('sequence')->all())->toBe([1])
        ->and($proposal->actionHistory()->pluck('sequence')->all())->toBe([1, 2]);
});
