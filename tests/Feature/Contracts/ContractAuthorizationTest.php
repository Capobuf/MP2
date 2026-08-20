<?php

use App\Domain\Company\Capability;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps Contract reads and mutations to exact-company capabilities', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }

    $contractA = Contract::factory()->for($companyA)->create();
    $contractB = Contract::factory()->for($companyB)->create();
    $conditionA = ContractCondition::factory()->forContract($contractA)->create(['created_by_id' => $user->id]);
    $projectA = Project::factory()->for($companyA)->create();
    $linkA = ProjectContractLink::factory()->forProjectAndContract($projectA, $contractA)->create();
    $attachmentA = Attachment::factory()->forContract($contractA)->create(['uploaded_by_id' => $user->id]);

    expect($user->can('view', $contractA))->toBeTrue()
        ->and($user->can('update', $contractA))->toBeTrue()
        ->and($user->can('update', $conditionA))->toBeTrue()
        ->and($user->can('update', $linkA))->toBeTrue()
        ->and($user->can('view', $attachmentA))->toBeTrue()
        ->and($user->can('view', $contractB))->toBeFalse()
        ->and($user->can('update', $contractB))->toBeFalse()
        ->and($user->can('delete', $contractA))->toBeFalse()
        ->and($user->can('delete', $linkA))->toBeFalse()
        ->and($user->can('delete', $attachmentA))->toBeFalse();
});
