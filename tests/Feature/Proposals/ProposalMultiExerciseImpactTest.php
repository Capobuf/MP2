<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('rejects a Proposal that would rewrite Contract conditions in a Closed year', function (): void {
    $company = Company::factory()->create();
    $open2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $closed2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $open2028 = Exercise::factory()->for($company)->create(['year' => 2028]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['cycle' => 'monthly', 'amount' => '100.00', 'valid_from' => '2026-01-01', 'valid_to' => null]);
    $historicalExpense = Expense::factory()->forExercise($closed2027)->for($contract)->create(['origin' => 'system']);
    $historicalLine = ExpenseLine::factory()->for($historicalExpense)->create(['type' => 'estimate', 'amount' => '1200.00']);
    closeExerciseFixture($closed2027, $actor);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $open2026, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanContract::class)->execute($actor, $proposal, $item, ProposalActionType::ChangeContractEconomics, [
        'condition_id' => $condition->id, 'amount' => '120.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_end',
        'requested_date' => '2026-08-24', 'confirmed_effective_date' => '2026-09-01', 'reason' => 'Nuovo accordo',
    ], 'Nuovo accordo', (string) Str::uuid(), 0);

    $impacts = ProposalImpactPlan::build($proposal->refresh());
    $closedImpact = collect($impacts)->firstWhere('exercise_id', $closed2027->id);

    expect($closedImpact['will_apply'])->toBeFalse()
        ->and($closedImpact['historical_divergence'])->toBeTrue()
        ->and($closedImpact['blocks'])->toBe([]);

    $operationId = (string) Str::uuid();
    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operationId))
        ->toThrow(ValidationException::class)
        ->and($historicalLine->fresh()->amount)->toBe('1200.00')
        ->and($closed2027->fresh()->revision)->toBe(0)
        ->and(Expense::query()->where('contract_id', $contract->id)->where('exercise_id', $open2028->id)->exists())->toBeFalse()
        ->and($condition->refresh()->validTo())->toBeNull()
        ->and($proposal->fresh()->status->value)->toBe('draft');
});

it('rolls back every open-year effect when multi-exercise application fails', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal, (string) Str::uuid(), [], [], fn (string $point) => $point === 'after_live_apply' ? throw new RuntimeException('failure') : null))
        ->toThrow(RuntimeException::class)
        ->and($proposal->fresh()->status->value)->toBe('draft');
});
