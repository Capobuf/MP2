<?php

use App\Actions\Proposals\DiscardProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('discards and retries a draft without reverting live reality', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $line->update(['amount' => '7.00']);
    $operationId = (string) Str::uuid();

    $discarded = app(DiscardProposal::class)->execute($actor, $proposal, 'Non più necessaria', $operationId);
    $retry = app(DiscardProposal::class)->execute($actor, $proposal->refresh(), 'Non più necessaria', $operationId);

    expect($retry->is($discarded))->toBeTrue()
        ->and($discarded->status->value)->toBe('discarded')
        ->and($discarded->discard_reason)->toBe('Non più necessaria')
        ->and($line->fresh()->amount)->toBe('7.00')
        ->and(AuditEvent::query()->where('operation_id', $operationId)->sole()->eventType())->toBe(AuditEventType::ProposalDiscarded)
        ->and(fn () => $discarded->update(['discard_reason' => 'Riscritta']))->toThrow(LogicException::class);
});

it('requires a reason and rejects unauthorized or terminal discard', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $manager = User::factory()->create();
    $other = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $proposal = app(InitializeProposal::class)->execute($manager, $company, $exercise, (string) Str::uuid());

    expect(fn () => app(DiscardProposal::class)->execute($manager, $proposal, ' ', (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(DiscardProposal::class)->execute($other, $proposal, 'Tentativo', (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    app(DiscardProposal::class)->execute($manager, $proposal, 'Fine', (string) Str::uuid());
    expect(fn () => app(DiscardProposal::class)->execute($manager, $proposal->refresh(), 'Ancora', (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});
