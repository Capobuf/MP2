<?php

use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('maps proposal and budget authority to the exact company capabilities', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
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

    $proposal->update(['status' => 'discarded', 'discarded_by_id' => $user->id, 'discarded_at' => now(), 'discard_reason' => 'Fine', 'discard_operation_id' => (string) Str::uuid()]);
    expect($user->can('update', $proposal->fresh()))->toBeFalse()
        ->and($user->can('approve', $proposal->fresh()))->toBeFalse();
});
