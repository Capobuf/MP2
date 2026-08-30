<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\Actions\Operations\RecalculateContractEstimates;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recalculates an imported Contract estimate without duplicating or double counting it', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $actor = User::factory()->platformAdmin()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'title' => 'Contratto da ripristinare', 'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'cycle' => 'annual', 'amount' => '120.00', 'valid_from' => '2026-01-01',
    ]);
    app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid());
    $sourceAllocation = Expense::query()->where('contract_id', $contract->id)->where('origin', 'system')->sole()->allocation();

    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    try {
        $restored = app(ImportBusinessBackup::class)->execute($actor, app(BusinessBackupValidator::class)->validate($artifact['path']));
    } finally {
        @unlink($artifact['path']);
    }

    $restoredContract = $restored->contracts()->where('title', 'Contratto da ripristinare')->sole();
    $restoredExercise = $restored->exercises()->where('year', 2026)->sole();
    expect($restoredContract->conditions()->sole()->created_by_id)->toBeNull()
        ->and($restoredContract->lifecycleFacts()->sole()->created_by_id)->toBeNull()
        ->and($restoredContract->expenses()->where('origin', 'system')->count())->toBe(1)
        ->and($restoredContract->expenses()->where('origin', 'system')->sole()->allocation())->toBe($sourceAllocation);

    app(RecalculateContractEstimates::class)->execute($actor, $restoredContract, [$restoredExercise], (string) Str::uuid());

    expect($restoredContract->expenses()->where('origin', 'system')->count())->toBe(1)
        ->and($restoredContract->expenses()->where('origin', 'system')->sole()->allocation())->toBe($sourceAllocation);
});
