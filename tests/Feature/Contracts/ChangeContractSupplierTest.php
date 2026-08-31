<?php

use App\Actions\Operations\UpdateContract;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

/** @return array{actor: User, company: Company, contract: Contract, old: Supplier, replacement: Supplier, exercise: Exercise} */
function supplierChangeFixture(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $old = Supplier::factory()->for($company)->create();
    $replacement = Supplier::factory()->for($company)->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->for($old)->create(['title' => 'Contratto']);

    return compact('actor', 'company', 'contract', 'old', 'replacement', 'exercise');
}

it('changes Supplier only before first economic use and keeps generated zero snapshots aligned', function () {
    ['actor' => $actor, 'contract' => $contract, 'replacement' => $replacement, 'exercise' => $exercise] = supplierChangeFixture();
    $estimate = Expense::factory()->forExercise($exercise)->create([
        'contract_id' => $contract->id, 'origin' => 'system', 'supplier_id' => $contract->supplier_id,
    ]);
    ExpenseLine::factory()->for($estimate)->create(['amount' => '0.00']);

    app(UpdateContract::class)->execute($actor, $contract, [
        'title' => 'Contratto', 'notes' => null, 'supplier_id' => $replacement->id,
    ], (string) Str::uuid());

    expect($contract->refresh()->supplier_id)->toBe($replacement->id)
        ->and($estimate->refresh()->supplier_id)->toBe($replacement->id)
        ->and($contract->hasEconomicUse())->toBeFalse();
});

it('blocks Supplier change after a non-zero generated Estimate', function () {
    ['actor' => $actor, 'contract' => $contract, 'replacement' => $replacement, 'exercise' => $exercise] = supplierChangeFixture();
    $estimate = Expense::factory()->forExercise($exercise)->create([
        'contract_id' => $contract->id, 'origin' => 'system', 'supplier_id' => $contract->supplier_id,
    ]);
    ExpenseLine::factory()->for($estimate)->create(['amount' => '0.01']);

    expect(fn () => app(UpdateContract::class)->execute($actor, $contract, [
        'title' => 'Contratto', 'notes' => null, 'supplier_id' => $replacement->id,
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and($contract->refresh()->supplier_id)->not->toBe($replacement->id);
});

it('blocks Supplier change after any active Actual Line including zero', function () {
    ['actor' => $actor, 'contract' => $contract, 'replacement' => $replacement, 'exercise' => $exercise] = supplierChangeFixture();
    $actual = Expense::factory()->forExercise($exercise)->create([
        'contract_id' => $contract->id, 'origin' => 'manual', 'supplier_id' => $contract->supplier_id,
    ]);
    ExpenseLine::factory()->actual()->for($actual)->create(['amount' => '0.00']);

    expect($contract->hasEconomicUse())->toBeTrue()
        ->and(fn () => app(UpdateContract::class)->execute($actor, $contract, [
            'title' => 'Contratto', 'notes' => null, 'supplier_id' => $replacement->id,
        ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and($contract->refresh()->supplier_id)->not->toBe($replacement->id);
});

it('keeps an archived historical Supplier readable but rejects selecting an archived replacement', function () {
    ['actor' => $actor, 'contract' => $contract, 'old' => $old, 'replacement' => $replacement] = supplierChangeFixture();
    $old->update(['archived_at' => now()]);

    app(UpdateContract::class)->execute($actor, $contract, [
        'title' => 'Contratto storico', 'notes' => null, 'supplier_id' => $old->id,
    ], (string) Str::uuid());

    expect($contract->refresh()->supplier->is($old))->toBeTrue();

    $replacement->update(['archived_at' => now()]);
    expect(fn () => app(UpdateContract::class)->execute($actor, $contract, [
        'title' => 'Contratto storico', 'notes' => null, 'supplier_id' => $replacement->id,
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and($contract->refresh()->supplier_id)->toBe($old->id);
});
