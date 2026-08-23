<?php

use App\Actions\Proposals\IncludeProposalSource;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('manually includes eligible closed Projects and cessated Contracts and permits their reopening plans', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);

    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2024-01-01']);
    ProjectTransition::factory()->forProject($project)->create(['from_state' => 'open', 'to_state' => 'closed', 'effective_date' => '2025-12-31', 'reason' => 'Chiusura precedente', 'created_by_id' => $actor->id]);
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2024-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create(['type' => 'cessation', 'declared_contractual_date' => '2025-12-30', 'state_change_date' => '2025-12-31', 'reason' => 'Cessazione precedente', 'created_by_id' => $actor->id]);

    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    expect($proposal->items)->toBeEmpty();

    $projectOperation = (string) Str::uuid();
    $projectItem = app(IncludeProposalSource::class)->execute($actor, $proposal, ProposalSourceType::Project, $project->id, $projectOperation, 0);
    $retry = app(IncludeProposalSource::class)->execute($actor, $proposal->refresh(), ProposalSourceType::Project, $project->id, $projectOperation, 1);
    $contractItem = app(IncludeProposalSource::class)->execute($actor, $proposal->refresh(), ProposalSourceType::Contract, $contract->id, (string) Str::uuid(), 1);

    app(PlanProject::class)->execute($actor, $proposal->refresh(), $projectItem->refresh(), ProposalActionType::PlanProjectTransition, [
        'from_state' => 'closed', 'to_state' => 'open', 'effective_date' => '2026-02-01', 'reason' => 'Riapertura approvata',
    ], 'Riapertura approvata', (string) Str::uuid(), 2);
    app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Spesa sul Progetto riaperto', 'exercise_id' => $exercise->id, 'supplier_id' => null,
        'cost_center_id' => null, 'project_id' => $project->id, 'project_item_id' => null,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '10.00', 'note' => null, 'annulled' => false]],
    ], 'Nuova allocazione per Progetto riaperto', (string) Str::uuid(), 3, ProposalActionType::CreateProjectAllocation);
    app(PlanContract::class)->execute($actor, $proposal->refresh(), $contractItem->refresh(), ProposalActionType::AddContractCondition, [
        'cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'amount' => '100.00', 'valid_from' => '2026-02-01', 'valid_to' => null, 'reason' => 'Nuove condizioni',
    ], 'Nuove condizioni', (string) Str::uuid(), 4);
    app(PlanContract::class)->execute($actor, $proposal->refresh(), $contractItem->refresh(), ProposalActionType::PlanContractLifecycle, [
        'type' => 'reactivation', 'declared_contractual_date' => '2026-02-01', 'effective_date' => '2026-02-01', 'next_expiry_date' => null, 'reason' => 'Riattivazione approvata',
    ], 'Riattivazione approvata', (string) Str::uuid(), 5);

    expect($retry->is($projectItem))->toBeTrue()
        ->and($projectItem->refresh()->result['planned_transitions'])->toHaveCount(1)
        ->and($proposal->items()->where('source_type', ProposalSourceType::Expense)->count())->toBe(1)
        ->and($contractItem->refresh()->result['planned_lifecycle'])->toHaveCount(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ProposalSourceIncluded)->count())->toBe(2);
});

it('rejects an ineligible or cross-company manual source', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $active = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2025-01-01']);
    $foreign = Project::factory()->create(['initial_state' => 'closed', 'initial_effective_date' => '2025-01-01']);

    expect(fn () => app(IncludeProposalSource::class)->execute($actor, $proposal, ProposalSourceType::Project, $active->id, (string) Str::uuid(), 0))->toThrow(ValidationException::class)
        ->and(fn () => app(IncludeProposalSource::class)->execute($actor, $proposal, ProposalSourceType::Project, $foreign->id, (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});
