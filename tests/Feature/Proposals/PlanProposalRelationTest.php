<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProposalRelation;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('records one idempotent economic-neutral link and rejects duplicates', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $project = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'project']);
    $contract = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'contract']);
    $payload = ['project_item_id' => $project->proposal_item_id, 'contract_item_id' => $contract->proposal_item_id];
    $operation = (string) Str::uuid();
    $action = app(PlanProposalRelation::class)->execute($user, $proposal, $payload, $operation, 0);
    $retry = app(PlanProposalRelation::class)->execute($user, $proposal->refresh(), $payload, $operation, 1);
    expect($retry->is($action))->toBeTrue()->and($project->refresh()->result)->toBe([]);
    expect(fn () => app(PlanProposalRelation::class)->execute($user, $proposal->refresh(), $payload, (string) Str::uuid(), 1))->toThrow(ValidationException::class);
});

it('restores the existing archived live link on approval and records its typed event', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $user = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
    }
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2025-01-01']);
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2025-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2025-01-01', 'valid_to' => null]);
    $link = ProjectContractLink::factory()->forProjectAndContract($project, $contract)->create(['note' => 'Contesto originale', 'archived_at' => now(), 'revision' => 3]);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $action = app(PlanProposalRelation::class)->execute($user, $proposal, ['project_origin_key' => $project->originKey(), 'contract_origin_key' => $contract->originKey()], (string) Str::uuid(), 0);
    $approvalOperation = (string) Str::uuid();

    app(ApproveProposal::class)->execute($user, $proposal->refresh(), $approvalOperation);

    expect($action->payload['restore_link_id'])->toBe($link->id)
        ->and(ProjectContractLink::query()->where('project_id', $project->id)->where('contract_id', $contract->id)->count())->toBe(1)
        ->and($link->refresh()->archived_at)->toBeNull()
        ->and($link->note)->toBe('Contesto originale')
        ->and($link->revision)->toBe(4)
        ->and(AuditEvent::query()->where('operation_id', $approvalOperation)->where('event_type', AuditEventType::ProjectContractLinkRestored)->count())->toBe(1);
});
