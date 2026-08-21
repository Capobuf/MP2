<?php

use App\Domain\Company\Capability;
use App\Filament\Pages\ContractDeadlines;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractAnnualSituationsRelationManager;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(function () {
    CarbonImmutable::setTestNow();
    DB::disableQueryLog();
});

/** @return Collection<int, string> */
function contractCapturedQueries(): Collection
{
    return collect(DB::getQueryLog())->map(fn (array $query): string => strtolower((string) $query['query']));
}

it('loads Contract list annual data in bounded set queries', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
        'capability' => Capability::View,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contracts = Contract::factory()->count(8)->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
    ]);

    foreach ($contracts as $contract) {
        ContractLifecycleFact::factory()->forContract($contract)->create([
            'declared_contractual_date' => '2026-01-01',
            'state_change_date' => '2026-01-01',
        ]);
        $expense = Expense::factory()->forExercise($exercise)->for($contract)->create([
            'project_id' => null,
            'direct_cost_center_id' => null,
            'origin' => 'manual',
        ]);
        ExpenseLine::factory()->for($expense)->actual()->create();
    }

    $this->actingAs($viewer);
    Filament::setTenant($company);
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListContracts::class)->assertCanSeeTableRecords($contracts);

    $queries = contractCapturedQueries();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_lifecycle_facts`'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_renewal_configurations`'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `expenses`'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`'))->count())->toBe(1);
});

it('renders all annual situations with bounded relation loads independent of Exercise count', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $contract = Contract::factory()->for($company)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    ContractCondition::factory()->forContract($contract)->create();
    $exercises = collect(range(2023, 2028))->map(function (int $year) use ($company, $contract): Exercise {
        $exercise = Exercise::factory()->for($company)->create(['year' => $year]);
        ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
        $expense = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'manual', 'direct_cost_center_id' => null]);
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '5.00']);

        return $exercise;
    });
    $this->actingAs($viewer);
    Filament::setTenant($company);
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ContractAnnualSituationsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertCanSeeTableRecords($exercises);

    $queries = contractCapturedQueries();
    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_conditions`'))->count())->toBeLessThanOrEqual(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_lifecycle_facts`'))->count())->toBeLessThanOrEqual(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_renewal_configurations`'))->count())->toBeLessThanOrEqual(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_exercise_classifications`'))->count())->toBeLessThanOrEqual(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `expenses`'))->count())->toBeLessThanOrEqual(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`'))->count())->toBeLessThanOrEqual(1);
});

it('renders and filters many deadline rows without per-Contract domain queries', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contracts = Contract::factory()->count(8)->for($company)->create();
    foreach ($contracts as $contract) {
        ContractLifecycleFact::factory()->forContract($contract)->create();
        ContractCondition::factory()->forContract($contract)->create();
        ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    }
    $this->actingAs($viewer);
    Filament::setTenant($company);
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ContractDeadlines::class)
        ->filterTable('expiry_interval', ['from' => '2026-01-01', 'until' => '2026-12-31'])
        ->assertCanSeeTableRecords($contracts);

    $queries = contractCapturedQueries();
    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_conditions`'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_lifecycle_facts`'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_renewal_configurations`'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `contract_exercise_classifications`'))->count())->toBeLessThanOrEqual(2);
});

it('aggregates all Contract Exercises with one grouped Line query', function () {
    $company = Company::factory()->create();
    $contract = Contract::factory()->for($company)->create();
    foreach (range(2023, 2028) as $year) {
        $exercise = Exercise::factory()->for($company)->create(['year' => $year]);
        $expense = Expense::factory()->forExercise($exercise)->for($contract)->create(['origin' => 'manual', 'direct_cost_center_id' => null]);
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '5.00']);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $totals = $contract->annualTotals();
    $queries = contractCapturedQueries()->filter(fn (string $sql): bool => str_contains($sql, 'from `expense_lines`'));

    expect($totals)->toHaveCount(6)
        ->and(collect($totals)->pluck('actual')->unique()->all())->toBe(['5.00'])
        ->and($queries)->toHaveCount(1)
        ->and($queries->first())->toContain('sum(expense_lines.amount)');
});
