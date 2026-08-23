<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalReadinessReason;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function readinessDeferralFixture(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();

    return compact('actor', 'company', 'source', 'destination', 'project', 'expense', 'line', 'proposal', 'item');
}

it('shows exact Carryover and Reprogramming annual impacts before approval', function (): void {
    extract(readinessDeferralFixture());
    app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '50.00',
    ], 'Riporto', (string) Str::uuid(), 0);

    $impacts = collect(ProposalImpactPlan::build($proposal->fresh(['items.actions', 'items.project'])))->keyBy('exercise_id');
    expect($impacts[$source->id]['allocation_delta'])->toBe('0.00')
        ->and($impacts[$destination->id]['allocation_delta'])->toBe('50.00');

    $second = readinessDeferralFixture();
    app(PlanProjectDeferral::class)->execute($second['actor'], $second['proposal'], $second['item'], [
        'source_exercise_id' => $second['source']->id,
        'destination_exercise_id' => $second['destination']->id,
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '50.00',
        'source_estimate_reductions' => [[
            'source_line_id' => $second['line']->id,
            'reduction_amount' => '50.00',
        ]],
    ], 'Riprogrammazione', (string) Str::uuid(), 0);
    $reprogramming = collect(ProposalImpactPlan::build($second['proposal']->fresh(['items.actions', 'items.project'])))->keyBy('exercise_id');

    expect($reprogramming[$second['source']->id]['allocation_delta'])->toBe('-50.00')
        ->and($reprogramming[$second['destination']->id]['allocation_delta'])->toBe('50.00');
});

it('keeps S7 Da riallineare precedence then exposes the exact over-limit reason', function (): void {
    extract(readinessDeferralFixture());
    app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '50.00',
    ], 'Riporto', (string) Str::uuid(), 0);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);
    $project->increment('revision');

    $reviewed = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $stale = $reviewed->items()->where('project_id', $project->id)->sole();
    expect($stale->readiness_state->value)->toBe('to_realign')
        ->and(collect($stale->readiness_reasons)->pluck('code')->all())->toContain(ProposalReadinessReason::SourceChanged->value);

    $realigned = app(RealignProposalItem::class)->execute(
        $actor,
        $reviewed->refresh(),
        $stale,
        ProposalRealignmentChoice::Keep,
        'Mantengo il Riporto pianificato',
        [],
        (string) Str::uuid(),
        $reviewed->revision,
    );

    expect($realigned->readiness_state->value)->toBe('inconsistent')
        ->and(collect($realigned->readiness_reasons)->pluck('code')->all())->toContain(ProposalReadinessReason::CarryoverAboveLimit->value);
});

it('detects unbalanced Reprogramming and conflicting modes with the closed reason vocabulary', function (): void {
    extract(readinessDeferralFixture());
    $action = app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'reprogramming',
        'reprogrammed_amount' => '50.00',
        'source_estimate_reductions' => [['source_line_id' => $line->id, 'reduction_amount' => '50.00']],
    ], 'Riprogrammazione', (string) Str::uuid(), 0);
    $payload = $action->payload;
    $payload['destination_plans'][0]['estimate_lines'][0]['amount'] = '49.00';
    DB::table('proposal_actions')->where('id', $action->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    $unbalanced = app(ProposalReadiness::class)->assessItem($item->fresh(['proposal', 'project', 'actions']));
    expect(collect($unbalanced['reasons'])->pluck('code')->all())->toContain(ProposalReadinessReason::ReprogrammingUnbalanced->value);

    $payload['mode'] = 'carryover';
    $payload['carryover_amount'] = '1.00';
    DB::table('proposal_actions')->where('id', $action->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    $conflict = app(ProposalReadiness::class)->assessItem($item->fresh(['proposal', 'project', 'actions']));
    expect(collect($conflict['reasons'])->pluck('code')->all())->toContain(ProposalReadinessReason::DeferralModesConflict->value);
});

it('uses the resulting planned source-year terminal state', function (): void {
    extract(readinessDeferralFixture());
    app(PlanProjectDeferral::class)->execute($actor, $proposal, $item, [
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '10.00',
    ], 'Riporto', (string) Str::uuid(), 0);
    app(PlanProject::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::PlanProjectTransition, [
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2026-12-31',
        'reason' => 'Conclusione',
    ], 'Conclusione', (string) Str::uuid(), 1);

    $assessment = app(ProposalReadiness::class)->assessItem($item->fresh(['proposal', 'project', 'actions']));
    expect($assessment['state']->value)->toBe('inconsistent')
        ->and(collect($assessment['reasons'])->pluck('code')->all())->toContain(ProposalReadinessReason::IncompatibleTransition->value);
});

it('includes a Project automatically when its only annual value is received Carryover', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'cancelled',
        'initial_effective_date' => '2026-01-01',
    ]);
    ProjectDeferral::factory()->carryover('10.00')->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
    ]);

    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    expect($proposal->items()->where('project_id', $project->id)->exists())->toBeTrue();
});
