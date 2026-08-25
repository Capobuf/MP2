<?php

use App\Actions\Operations\DetachAttachment;
use App\Actions\Operations\SetContractArchived;
use App\Actions\Operations\SetProjectContractLinkArchived;
use App\Actions\Operations\UpdateContractRenewal;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

function terminalContractContext(string $terminalType): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01', 'next_expiry_date' => '2026-12-31', 'renewal_anchor_date' => '2026-12-31',
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation', 'declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01',
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => $terminalType, 'declared_contractual_date' => '2026-08-01', 'state_change_date' => '2026-08-01', 'reason' => 'Termine',
    ]);
    $condition = ContractCondition::factory()->forContract($contract)->create();
    $classification = ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'manual', 'direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '5.00']);
    $link = ProjectContractLink::factory()->forProjectAndContract(Project::factory()->for($company)->create(), $contract)->create();
    $attachment = Attachment::factory()->forContract($contract)->create(['uploaded_by_id' => $actor->id]);

    return compact('actor', 'contract', 'condition', 'classification', 'expense', 'link', 'attachment', 'exercise');
}

it('archives and restores terminal Contracts while preserving every identity and value', function (string $terminalType, string $expectedState) {
    extract(terminalContractContext($terminalType));
    $operationId = (string) Str::uuid();

    $archived = app(SetContractArchived::class)->execute($actor, $contract, true, $operationId, $contract->revision);
    $retry = app(SetContractArchived::class)->execute($actor, $contract, true, $operationId, $contract->revision);
    $restored = app(SetContractArchived::class)->execute($actor, $archived, false, (string) Str::uuid(), $archived->revision);

    expect($retry->id)->toBe($contract->id)
        ->and($restored->id)->toBe($contract->id)
        ->and($restored->isArchived())->toBeFalse()
        ->and($restored->stateAtDate('2026-08-21')->value)->toBe($expectedState)
        ->and($condition->refresh()->contract_id)->toBe($contract->id)
        ->and($classification->refresh()->contract_id)->toBe($contract->id)
        ->and($expense->refresh()->contract_id)->toBe($contract->id)
        ->and($link->refresh()->contract_id)->toBe($contract->id)
        ->and($attachment->refresh()->contract_id)->toBe($contract->id)
        ->and($restored->annualTotals()[$exercise->id]['actual'])->toBe('5.00')
        ->and(AuditEvent::query()->orderBy('id')->pluck('event_type')->all())->toBe([
            AuditEventType::ContractArchived,
            AuditEventType::ContractRestored,
        ]);
})->with([
    ['cessation', 'cessated'],
    ['cancellation', 'cancelled'],
]);

it('rejects archive for Planned or Active Contracts and stale revisions', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageOperations]);
    $active = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01']);
    $planned = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2027-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);

    expect(fn () => app(SetContractArchived::class)->execute($actor, $active, true, (string) Str::uuid(), $active->revision))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SetContractArchived::class)->execute($actor, $planned, true, (string) Str::uuid(), $planned->revision))
        ->toThrow(ValidationException::class);

    ['actor' => $terminalActor, 'contract' => $terminal] = terminalContractContext('cessation');
    expect(fn () => app(SetContractArchived::class)->execute($terminalActor, $terminal, true, (string) Str::uuid(), 99))
        ->toThrow(ValidationException::class)
        ->and($terminal->refresh()->isArchived())->toBeFalse();
});

it('rejects renewal link-state and attachment-detachment activity while the Contract is archived', function () {
    ['actor' => $actor, 'contract' => $contract, 'link' => $link, 'attachment' => $attachment] = terminalContractContext('cessation');
    $archived = app(SetContractArchived::class)->execute(
        $actor,
        $contract,
        true,
        (string) Str::uuid(),
        $contract->revision,
    );

    expect(fn () => app(UpdateContractRenewal::class)->execute($actor, $archived, [
        'effective_from' => '2026-09-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => 12,
        'notice_days' => 30,
        'impact_confirmed' => true,
        'expected_revision' => $archived->revision,
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(fn () => app(SetProjectContractLinkArchived::class)->execute(
            $actor,
            $link,
            true,
            (string) Str::uuid(),
            $link->revision,
        ))->toThrow(ValidationException::class)
        ->and(fn () => app(DetachAttachment::class)->execute(
            $actor,
            $attachment,
            (string) Str::uuid(),
        ))->toThrow(ValidationException::class);

    expect($link->refresh()->isArchived())->toBeFalse()
        ->and($attachment->refresh()->isDetached())->toBeFalse()
        ->and($archived->refresh()->renewalConfigurations()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', '!=', AuditEventType::ContractArchived)->count())->toBe(0);
});
