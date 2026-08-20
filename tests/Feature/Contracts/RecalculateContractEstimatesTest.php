<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\RecalculateContractEstimates;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\SetExpenseReversed;
use App\Actions\Operations\UpdateExpense;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

function estimateFixture(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create(['declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01']);

    return compact('actor', 'company', 'exercise', 'contract');
}

it('preserves generated Expense and Line identity including a recalculated zero', function () {
    ['actor' => $actor, 'exercise' => $exercise, 'contract' => $contract] = estimateFixture();
    $condition = ContractCondition::factory()->forContract($contract)->create(['amount' => '10.00', 'valid_from' => '2026-01-01']);

    app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid());
    $expense = Expense::query()->sole();
    $line = ExpenseLine::query()->sole();
    $condition->update(['amount' => '20.00']);
    app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid());
    $condition->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'reason' => 'Errore']);
    app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid());

    expect(Expense::query()->sole()->is($expense))->toBeTrue()
        ->and(ExpenseLine::query()->sole()->is($line))->toBeTrue()
        ->and($line->refresh()->amount)->toBe('0.00');
});

it('never materializes zero and rolls persistence back with its audit', function () {
    ['actor' => $actor, 'exercise' => $exercise, 'contract' => $contract] = estimateFixture();
    ContractCondition::factory()->forContract($contract)->create(['amount' => '0.00', 'valid_from' => '2026-01-01']);

    app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid());
    expect(Expense::query()->count())->toBe(0);

    ContractCondition::query()->update(['amount' => '50.00']);
    AuditEvent::creating(fn () => throw new RuntimeException('audit unavailable'));
    expect(fn () => app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'audit unavailable')
        ->and(Expense::query()->count())->toBe(0);
    AuditEvent::flushEventListeners();
});

it('blocks every manual mutation path for a generated estimate and line', function () {
    ['actor' => $actor, 'exercise' => $exercise, 'contract' => $contract] = estimateFixture();
    ContractCondition::factory()->forContract($contract)->create(['amount' => '10.00', 'valid_from' => '2026-01-01']);
    app(RecalculateContractEstimates::class)->execute($actor, $contract, [$exercise], (string) Str::uuid());
    $expense = Expense::query()->sole();
    $line = ExpenseLine::query()->sole();

    foreach ([
        fn () => app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '1.00'], (string) Str::uuid()),
        fn () => app(UpdateExpenseLine::class)->execute($actor, $line, ['type' => ExpenseLineType::Estimate->value, 'amount' => '1.00'], (string) Str::uuid()),
        fn () => app(SetExpenseLineActive::class)->execute($actor, $line, false, (string) Str::uuid()),
        fn () => app(SetExpenseReversed::class)->execute($actor, $expense, true, 'Tentativo', (string) Str::uuid()),
        fn () => app(UpdateExpense::class)->updateDetails($actor, $expense, ['description' => 'Mutata'], (string) Str::uuid()),
    ] as $mutation) {
        expect($mutation)->toThrow(ValidationException::class);
    }
});
