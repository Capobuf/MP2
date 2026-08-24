<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionReplay;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function deferralPlanningFixture(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::ManageProposals,
    ]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2026-01-01',
    ]);
    $expense = Expense::factory()->forExercise($source)->for($project)->create([
        'description' => 'Analisi',
        'notes' => 'Fase origine',
    ]);
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00', 'note' => 'Riga A']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();

    return compact('actor', 'company', 'source', 'destination', 'project', 'expense', 'line', 'proposal', 'item');
}

it('plans partial Carryover with captured source context and no live economic write', function (): void {
    extract(deferralPlanningFixture());
    $operation = (string) Str::uuid();

    $action = app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '50.00',
    ], 'Riporto prudenziale', $operation, 0);
    $retry = app(PlanProjectDeferral::class)->execute($actor, $proposal->refresh(), $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '50.00',
    ], 'Riporto prudenziale', $operation, 1);
    $replayed = app(ProposalActionReplay::class)->replay($item->refresh(), $item->baseline, [$action->fresh()]);

    expect($retry->is($action))->toBeTrue()
        ->and($action->payload['mode'])->toBe('carryover')
        ->and($action->payload['source_context']['allocation'])->toBe('100.00')
        ->and($action->payload['source_context']['actual'])->toBe('40.00')
        ->and($action->payload['source_context']['maximum_transferable'])->toBe('60.00')
        ->and($action->payload['source_context']['project_fingerprint'])->toHaveLength(64)
        ->and(data_get($item->refresh()->result, 'incoming_deferral.carryover_amount'))->toBe('50.00')
        ->and(data_get($replayed, 'incoming_deferral.carryover_amount'))->toBe('50.00')
        ->and(ProjectDeferral::query()->count())->toBe(0)
        ->and($line->refresh()->amount)->toBe('100.00')
        ->and(Expense::query()->where('exercise_id', $destination->id)->count())->toBe(0);
});

it('generates balanced one-to-one Reprogramming preview from explicit source lines', function (): void {
    extract(deferralPlanningFixture());
    $second = ExpenseLine::factory()->for($expense)->create(['amount' => '20.00', 'note' => 'Riga B']);

    $action = app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '60.00',
        'source_estimate_reductions' => [
            ['source_line_id' => $line->id, 'reduction_amount' => '50.00'],
            ['source_line_id' => $second->id, 'reduction_amount' => '10.00'],
        ],
    ], 'Piano rinviato', (string) Str::uuid(), 0);

    expect($action->payload['carryover_amount'])->toBe('0.00')
        ->and($action->payload['reprogrammed_amount'])->toBe('60.00')
        ->and($action->payload['source_estimate_reductions'])->toHaveCount(2)
        ->and($action->payload['destination_plans'])->toHaveCount(1)
        ->and($action->payload['destination_plans'][0]['copied_from_origin_key'])->toBe($expense->originKey())
        ->and($action->payload['destination_plans'][0]['estimate_lines'])->toHaveCount(2)
        ->and($action->payload['source_context']['referenced_estimates'])->toHaveCount(2)
        ->and(Expense::query()->where('exercise_id', $destination->id)->count())->toBe(0)
        ->and($line->refresh()->amount)->toBe('100.00');
});

it('requires an explicit destination supplier choice when the source supplier is Archived', function (): void {
    extract(deferralPlanningFixture());
    $archived = Supplier::factory()->for($company)->archived()->create();
    $expense->update(['supplier_id' => $archived->id]);
    $project->increment('revision');
    $snapshot = ProposalSourceSnapshot::project($project->refresh(), $destination->id);
    $item->update([
        'baseline_revision' => $project->revision,
        'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
        'baseline' => $snapshot,
        'result' => $snapshot['plan_baseline'],
    ]);
    $base = [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '10.00',
    ];

    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        ...$base,
        'source_estimate_reductions' => [['source_line_id' => $line->id, 'reduction_amount' => '10.00']],
    ], 'Scelta Fornitore mancante', (string) Str::uuid(), 0))->toThrow(ValidationException::class);

    $action = app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        ...$base,
        'source_estimate_reductions' => [[
            'source_line_id' => $line->id,
            'reduction_amount' => '10.00',
            'destination_supplier_id' => null,
        ]],
    ], 'Confermo Nessun Fornitore', (string) Str::uuid(), 0);

    expect(data_get($action->payload, 'destination_plans.0.supplier_id'))->toBeNull()
        ->and(data_get($action->payload, 'destination_plans.0.copied_from_origin_key'))->toBe($expense->originKey());
});

it('rejects missing reasons invalid limits unbalanced or implicit source selection', function (): void {
    extract(deferralPlanningFixture());
    $base = ['source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id];

    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        ...$base, 'mode' => 'carryover', 'carryover_amount' => '10.00',
    ], null, (string) Str::uuid(), 0))->toThrow(ValidationException::class)
        ->and(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
            ...$base, 'mode' => 'carryover', 'carryover_amount' => '60.01',
        ], 'Troppo alto', (string) Str::uuid(), 0))->toThrow(ValidationException::class)
        ->and(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
            ...$base, 'mode' => 'reprogramming', 'reprogrammed_amount' => '10.00', 'source_estimate_reductions' => [],
        ], 'Senza righe', (string) Str::uuid(), 0))->toThrow(ValidationException::class)
        ->and(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
            ...$base, 'mode' => 'reprogramming', 'reprogrammed_amount' => '9.00',
            'source_estimate_reductions' => [['source_line_id' => $line->id, 'reduction_amount' => '10.00']],
        ], 'Sbilanciata', (string) Str::uuid(), 0))->toThrow(ValidationException::class);

    expect(ProjectDeferral::query()->count())->toBe(0)
        ->and($proposal->refresh()->revision)->toBe(0);
});

it('rejects unauthorized, foreign and Closed Proposal deferral planning atomically', function (): void {
    extract(deferralPlanningFixture());
    CompanyCapability::query()->where('company_id', $company->id)->where('user_id', $actor->id)->delete();
    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
        'mode' => 'carryover', 'carryover_amount' => '10.00',
    ], 'Non autorizzato', (string) Str::uuid(), 0))->toThrow(AuthorizationException::class);

    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $foreignCompany = Company::factory()->create();
    $foreignSource = Exercise::factory()->for($foreignCompany)->create(['year' => 2026]);
    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $foreignSource->id, 'destination_exercise_id' => $destination->id,
        'mode' => 'carryover', 'carryover_amount' => '10.00',
    ], 'Altro tenant', (string) Str::uuid(), 0))->toThrow(ValidationException::class);

    closeExerciseFixture($source, $actor);
    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
        'mode' => 'carryover', 'carryover_amount' => '10.00',
    ], 'Esercizio chiuso', (string) Str::uuid(), 0))->toThrow(ValidationException::class);

    expect($proposal->refresh()->revision)->toBe(0)
        ->and($proposal->actions()->count())->toBe(0)
        ->and(ProjectDeferral::query()->count())->toBe(0);
});

it('preserves a live baseline and rejects planning after the Project source became stale', function (): void {
    extract(deferralPlanningFixture());
    expect(data_get($proposal->items()->where('project_id', $project->id)->sole()->baseline, 'plan_baseline.incoming_deferral.mode'))->toBe('none');

    $project->increment('revision');
    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal->refresh(), $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '10.00',
    ], 'Dati vecchi', (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});

it('initializes a Proposal from an already-live deferral without recalculating it', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    ProjectDeferral::factory()->carryover('25.00')->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
    ]);

    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $incoming = data_get($proposal->items()->where('project_id', $project->id)->sole()->baseline, 'plan_baseline.incoming_deferral');

    expect($incoming['mode'])->toBe('carryover')
        ->and($incoming['carryover_amount'])->toBe('25.00');
});

it('keeps the three payload modes mutually exclusive', function (): void {
    $context = [
        'source_exercise_id' => 1,
        'project_revision' => 0,
        'project_fingerprint' => str_repeat('a', 64),
        'allocation' => '10.00',
        'actual' => '0.00',
        'maximum_transferable' => '10.00',
        'referenced_estimates' => [],
    ];
    $base = [
        'source_exercise_id' => 1,
        'destination_exercise_id' => 2,
        'source_context' => $context,
    ];

    expect(ProposalActionPayload::validate(ProposalActionType::PlanProjectDeferral, [
        ...$base, 'mode' => 'none', 'carryover_amount' => '0.00', 'reprogrammed_amount' => '0.00',
        'source_estimate_reductions' => [], 'destination_plans' => [],
    ])['mode'])->toBe('none')
        ->and(fn () => ProposalActionPayload::validate(ProposalActionType::PlanProjectDeferral, [
            ...$base, 'mode' => 'carryover', 'carryover_amount' => '1.00', 'reprogrammed_amount' => '1.00',
            'source_estimate_reductions' => [], 'destination_plans' => [],
        ]))->toThrow(ValidationException::class);
});

it('requires typed Nuova allocazione with Note for an already-live Project', function (): void {
    extract(deferralPlanningFixture());
    $payload = [
        'description' => 'Attività aggiuntiva',
        'notes' => null,
        'exercise_id' => $destination->id,
        'supplier_id' => null,
        'cost_center_id' => null,
        'project_id' => $project->id,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => null,
            'amount' => '20.00',
            'note' => null,
            'annulled' => false,
        ]],
    ];

    expect(fn () => app(PlanExpense::class)->create($actor, $proposal, $payload, null, (string) Str::uuid(), 0))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(PlanExpense::class)->create($actor, $proposal, $payload, null, (string) Str::uuid(), 0, ProposalActionType::CreateProjectAllocation))
        ->toThrow(ValidationException::class);

    $action = app(PlanExpense::class)->create(
        $actor,
        $proposal,
        $payload,
        'Capienza aggiuntiva indipendente',
        (string) Str::uuid(),
        0,
        ProposalActionType::CreateProjectAllocation,
    );

    expect($action->action_type)->toBe(ProposalActionType::CreateProjectAllocation)
        ->and($action->reason)->toBe('Capienza aggiuntiva indipendente')
        ->and($action->item->copied_from_origin_key)->toBeNull()
        ->and(Expense::query()->where('exercise_id', $destination->id)->count())->toBe(0);
});

it('keeps generic child creation valid for a Project created in the same Proposal', function (): void {
    extract(deferralPlanningFixture());
    $projectAction = app(PlanProject::class)->create($actor, $proposal, [
        'title' => 'Nuovo progetto',
        'initial_state' => 'planned',
        'initial_effective_date' => '2027-01-01',
        'exercise_id' => $destination->id,
        'cost_center_id' => null,
    ], (string) Str::uuid(), 0);

    $expenseAction = app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Prima attività',
        'exercise_id' => $destination->id,
        'project_item_id' => $projectAction->item->proposal_item_id,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '1.00', 'note' => null, 'annulled' => false,
        ]],
    ], null, (string) Str::uuid(), 1);

    expect($expenseAction->action_type)->toBe(ProposalActionType::CreateExpense);
});
