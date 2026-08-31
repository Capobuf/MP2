<?php

use App\Actions\Operations\UpdateContractRenewal;
use App\Domain\Company\AuditEventType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractRenewalConfiguration;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('maps Contract reads and mutations to exact-company capabilities', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $companyA->id,
            'user' => $user,
            'permissions' => $capability,
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

it('reauthorizes an idempotent renewal retry before returning its receipt', function () {
    $actor = User::factory()->create();
    $unauthorized = User::factory()->create();
    $company = Company::factory()->create();
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => '2026-12-31',
        'renewal_anchor_date' => '2026-12-31',
    ]);
    $configuration = ContractRenewalConfiguration::query()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'effective_from' => '2026-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => 12,
        'notice_days' => 30,
        'created_by_id' => $actor->id,
    ]);
    $operationId = (string) Str::uuid();
    AuditEvent::query()->create([
        'operation_id' => $operationId,
        'company_id' => $company->id,
        'actor_id' => $actor->id,
        'event_type' => AuditEventType::ContractRenewalChanged,
        'subject_type' => ContractRenewalConfiguration::class,
        'subject_id' => $configuration->id,
        'affected_exercise_ids' => [],
        'effective_from' => '2026-01-01',
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
        'reference_type' => Contract::class,
        'reference_id' => $contract->id,
    ]);

    expect(fn () => app(UpdateContractRenewal::class)->execute($unauthorized, $contract, [
        'effective_from' => '2026-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => 12,
        'notice_days' => 30,
        'impact_confirmed' => true,
        'expected_revision' => $contract->revision,
    ], $operationId))->toThrow(AuthorizationException::class);
});
