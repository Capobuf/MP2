<?php

use App\Actions\Operations\CreateExpense;
use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{actor: User, company: Company, exercise: Exercise, contract: Contract, supplier: Supplier, costCenter: CostCenter} */
function contractActualFixture(bool $active = true): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => $active ? '2026-01-01' : '2027-01-01',
        'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);
    if ($active) {
        ContractLifecycleFact::factory()->forContract($contract)->create([
            'declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01',
        ]);
    }
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create(['cost_center_id' => $costCenter->id]);

    return compact('actor', 'company', 'exercise', 'contract', 'supplier', 'costCenter');
}

it('creates an ordinary Contract Actual with inherited Supplier and annual classification', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract, 'supplier' => $supplier, 'costCenter' => $costCenter] = contractActualFixture();

    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'contract_id' => $contract->id,
        'description' => 'Consumo rilevato',
        'lines' => [
            ['type' => 'actual', 'amount' => '25.00'],
            ['type' => 'actual', 'amount' => '-5.00', 'note' => 'Rimborso parziale'],
            ['type' => 'actual', 'amount' => '0.00', 'note' => 'Rilevazione senza importo'],
        ],
    ], (string) Str::uuid());

    expect($expense->contract_id)->toBe($contract->id)
        ->and($expense->project_id)->toBeNull()
        ->and($expense->supplier_id)->toBe($supplier->id)
        ->and($expense->direct_cost_center_id)->toBeNull()
        ->and($expense->costCenterLabel())->toContain($costCenter->name)
        ->and($expense->lines()->get()->map(fn ($line): string => $line->lineType()->value)->unique()->all())->toBe(['actual'])
        ->and($contract->refresh()->annualTotals()[$exercise->id]['actual'])->toBe('20.00')
        ->and($exercise->actual())->toBe('20.00')
        ->and($contract->stateAtDate('2026-08-21')->value)->toBe('active');
});

it('rejects ordinary Actuals for a Planned Contract without inferring activation or estimates', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract] = contractActualFixture(false);

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'contract_id' => $contract->id,
        'description' => 'Non ammessa',
        'lines' => [['type' => 'actual', 'amount' => '1.00']],
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0)
        ->and(ContractLifecycleFact::query()->count())->toBe(0);
});

it('accepts every declared terminal Actual kind with a mandatory activity note', function (string $kind) {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract] = contractActualFixture();
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation', 'declared_contractual_date' => '2026-08-01', 'state_change_date' => '2026-08-01', 'reason' => 'Fine rapporto',
    ]);

    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'contract_id' => $contract->id,
        'description' => 'Documento terminale',
        'actual_kind' => $kind,
        'activity_note' => 'Documento ricevuto dopo la cessazione',
        'lines' => [['type' => 'actual', 'amount' => '10.00']],
    ], (string) Str::uuid());

    expect($expense->contract_id)->toBe($contract->id)
        ->and($contract->refresh()->stateAtDate('2026-08-21')->value)->toBe('cessated');
})->with(['late', 'cessation_cost', 'reimbursement', 'corrective']);

it('accepts a declared terminal Actual for a Cancelled Contract', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract] = contractActualFixture();
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cancellation', 'declared_contractual_date' => '2026-08-01', 'state_change_date' => '2026-08-01', 'reason' => 'Annullamento dichiarato',
    ]);

    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'contract_id' => $contract->id,
        'description' => 'Rimborso successivo all’annullamento',
        'actual_kind' => 'reimbursement',
        'activity_note' => 'Rimborso documentato',
        'lines' => [['type' => 'actual', 'amount' => '-10.00', 'note' => 'Rimborso']],
    ], (string) Str::uuid());

    expect($expense->contract_id)->toBe($contract->id)
        ->and($contract->refresh()->stateAtDate('2026-08-21')->value)->toBe('cancelled');
});

it('rejects Contract Estimates and terminal Actuals without an explicit declaration and note', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract] = contractActualFixture();

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id, 'contract_id' => $contract->id, 'description' => 'Stima manuale',
        'lines' => [['type' => 'estimate', 'amount' => '10.00']],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation', 'declared_contractual_date' => '2026-08-01', 'state_change_date' => '2026-08-01', 'reason' => 'Fine rapporto',
    ]);
    expect(fn () => app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id, 'contract_id' => $contract->id, 'description' => 'Tardiva non dichiarata',
        'lines' => [['type' => 'actual', 'amount' => '10.00']],
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(Expense::query()->count())->toBe(0);
});

it('continues with its historically assigned archived Supplier and applies Contract rules to added Lines', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract, 'supplier' => $supplier] = contractActualFixture();
    $supplier->update(['archived_at' => now()]);
    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id, 'contract_id' => $contract->id, 'description' => 'Continuità',
        'lines' => [['type' => 'actual', 'amount' => '1.00']],
    ], (string) Str::uuid());

    expect($expense->supplier_id)->toBe($supplier->id)
        ->and(fn () => app(CreateExpenseLine::class)->execute($actor, $expense, [
            'type' => 'estimate', 'amount' => '1.00',
        ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and($expense->lines()->count())->toBe(1);
});

it('revalidates Contract state and declarations when updating or restoring an Actual Line', function () {
    ['actor' => $actor, 'company' => $company, 'exercise' => $exercise, 'contract' => $contract] = contractActualFixture();
    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id, 'contract_id' => $contract->id, 'description' => 'Attività',
        'lines' => [['type' => 'actual', 'amount' => '1.00']],
    ], (string) Str::uuid());
    $line = $expense->lines()->sole();
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation', 'declared_contractual_date' => '2026-08-01', 'state_change_date' => '2026-08-01', 'reason' => 'Fine rapporto',
    ]);

    expect(fn () => app(UpdateExpenseLine::class)->execute($actor, $line, ['type' => 'actual', 'amount' => '2.00'], (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    app(UpdateExpenseLine::class)->execute($actor, $line, [
        'type' => 'actual', 'amount' => '2.00', 'actual_kind' => 'corrective', 'activity_note' => 'Correzione dichiarata',
    ], (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $line, false, (string) Str::uuid());

    expect(fn () => app(SetExpenseLineActive::class)->execute($actor, $line, true, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    app(SetExpenseLineActive::class)->execute($actor, $line, true, (string) Str::uuid(), [
        'actual_kind' => 'late', 'activity_note' => 'Ripristino tardivo dichiarato',
    ]);

    expect($line->refresh()->amount)->toBe('2.00')->and($line->isAnnulled())->toBeFalse();
});
