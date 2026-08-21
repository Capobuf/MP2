<?php

use App\Domain\Company\Capability;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps proposal and budget authority to the exact company capabilities', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $proposal = Proposal::factory()->for($company)->create();
    $otherProposal = Proposal::factory()->for($other)->create();
    $budget = BudgetSnapshot::factory()->for($proposal)->create();

    expect($user->can('view', $proposal))->toBeTrue()
        ->and($user->can('update', $proposal))->toBeTrue()
        ->and($user->can('approve', $proposal))->toBeTrue()
        ->and($user->can('view', $budget))->toBeTrue()
        ->and($user->can('update', $budget))->toBeFalse()
        ->and($user->can('delete', $proposal))->toBeFalse()
        ->and($user->can('view', $otherProposal))->toBeFalse();
});
