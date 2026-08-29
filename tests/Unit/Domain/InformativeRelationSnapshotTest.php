<?php

use App\Domain\Closing\ClosingSnapshotPayload;
use App\Domain\Proposals\BudgetSnapshotPayload;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('materializes Collegato a in Budget and Closing Snapshots', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create();
    $project = Project::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    ProjectContractLink::factory()->forProjectAndContract($project, $contract)->create(['note' => 'Contesto condiviso']);

    $projectItem = ProposalItem::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'source_type' => 'project',
        'project_id' => $project->id,
        'baseline_revision' => $project->revision,
        'baseline_fingerprint' => str_repeat('a', 64),
    ]);
    $contractItem = ProposalItem::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'source_type' => 'contract',
        'contract_id' => $contract->id,
        'baseline_revision' => $contract->revision,
        'baseline_fingerprint' => str_repeat('b', 64),
    ]);
    $proposal->load(['exercise', 'items.actions', 'actions']);

    $budget = BudgetSnapshotPayload::build($proposal, [
        (string) $projectItem->proposal_item_id => $project,
        (string) $contractItem->proposal_item_id => $contract,
    ], []);
    $closing = ClosingSnapshotPayload::build($exercise, []);

    $budgetProject = collect($budget['rows'])->firstWhere('origin_key', $project->originKey());
    $closingProject = collect($closing['rows'])->firstWhere('origin_key', $project->originKey());

    expect($budgetProject['detail']['relations'])->toBe([[
        'type' => 'linked_to',
        'project' => ['origin_key' => $project->originKey(), 'label' => $project->title],
        'contract' => ['origin_key' => $contract->originKey(), 'label' => $contract->title],
        'note' => 'Contesto condiviso',
    ]])->and($closingProject['detail']['relations'])->toBe([[
        'type' => 'linked',
        'link_id' => ProjectContractLink::query()->sole()->id,
        'project_origin_key' => $project->originKey(),
        'project_label' => $project->title,
        'contract_origin_key' => $contract->originKey(),
        'contract_label' => $contract->title,
        'note' => 'Contesto condiviso',
    ]]);
});
