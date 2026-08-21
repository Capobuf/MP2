<?php

use App\Actions\Operations\CreateProjectContractLink;
use App\Actions\Operations\SetProjectContractLinkArchived;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function projectContractLinkContext(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }

    return [$actor, $company, Project::factory()->for($company)->create(), Contract::factory()->for($company)->create()];
}

it('creates one symmetric informational link without propagating economics and retries idempotently', function () {
    [$actor, $company, $project, $contract] = projectContractLinkContext();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $projectExpense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($projectExpense)->create(['amount' => '20.00']);
    $contractExpense = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'manual', 'direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($contractExpense)->actual()->create(['amount' => '7.00']);
    $operationId = (string) Str::uuid();

    $link = app(CreateProjectContractLink::class)->execute($actor, $project, $contract, 'Contesto operativo', $operationId);
    $retry = app(CreateProjectContractLink::class)->execute($actor, $project, $contract, 'Contesto operativo', $operationId);

    expect($retry->is($link))->toBeTrue()
        ->and($link->company_id)->toBe($company->id)
        ->and($projectExpense->refresh()->project_id)->toBe($project->id)
        ->and($projectExpense->contract_id)->toBeNull()
        ->and($contractExpense->refresh()->contract_id)->toBe($contract->id)
        ->and($contractExpense->project_id)->toBeNull()
        ->and($project->annualTotals()[$exercise->id]['allocation'])->toBe('20.00')
        ->and($contract->annualTotals()[$exercise->id]['actual'])->toBe('7.00')
        ->and(AuditEvent::query()->sole()->event_type)->toBe(AuditEventType::ProjectContractLinked);

    expect(fn () => app(CreateProjectContractLink::class)->execute($actor, $project, $contract, null, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

it('archives and restores the same link identity without deleting or propagating values', function () {
    [$actor, , $project, $contract] = projectContractLinkContext();
    $link = app(CreateProjectContractLink::class)->execute($actor, $project, $contract, null, (string) Str::uuid());

    $archived = app(SetProjectContractLinkArchived::class)->execute($actor, $link, true, (string) Str::uuid(), $link->revision);
    $restored = app(SetProjectContractLinkArchived::class)->execute($actor, $archived, false, (string) Str::uuid(), $archived->revision);

    expect($archived->id)->toBe($link->id)
        ->and($restored->id)->toBe($link->id)
        ->and($restored->isArchived())->toBeFalse()
        ->and($restored->revision)->toBe(2)
        ->and(AuditEvent::query()->orderBy('id')->pluck('event_type')->all())->toBe([
            AuditEventType::ProjectContractLinked,
            AuditEventType::ProjectContractLinkArchived,
            AuditEventType::ProjectContractLinkRestored,
        ]);
});

it('rejects cross-company and archived endpoints plus stale link revisions', function () {
    [$actor, , $project, $contract] = projectContractLinkContext();
    $otherContract = Contract::factory()->create();
    $archivedProject = Project::factory()->for($project->company)->archived()->create();

    expect(fn () => app(CreateProjectContractLink::class)->execute($actor, $project, $otherContract, null, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(CreateProjectContractLink::class)->execute($actor, $archivedProject, $contract, null, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $link = app(CreateProjectContractLink::class)->execute($actor, $project, $contract, null, (string) Str::uuid());
    expect(fn () => app(SetProjectContractLinkArchived::class)->execute($actor, $link, true, (string) Str::uuid(), 99))
        ->toThrow(ValidationException::class)
        ->and($link->refresh()->isArchived())->toBeFalse();
});
