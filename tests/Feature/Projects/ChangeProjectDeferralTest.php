<?php

use App\Actions\Operations\ChangeProjectDeferral;
use App\Actions\Operations\CreateProjectTransition;
use App\Actions\Proposals\ApplyProjectDeferral;
use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Filament\Pages\CompanyAudit;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectTransition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function liveDeferralFixture(string $mode = 'carryover'): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['description' => 'Piano origine']);
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00', 'note' => 'Stima origine']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);

    if ($mode === 'carryover') {
        ProjectDeferral::factory()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'source_exercise_id' => $source->id,
            'destination_exercise_id' => $destination->id,
            'mode' => 'carryover',
            'carryover_amount' => '50.00',
            'carryover_state' => 'provisional',
        ]);
    } else {
        app(ApplyProjectDeferral::class)->executeDirect($project, $source, $destination, [
            'mode' => 'reprogramming',
            'reprogrammed_amount' => '30.00',
            'source_estimate_reductions' => [[
                'source_expense_id' => $expense->id,
                'source_expense_origin_key' => $expense->originKey(),
                'source_expense_revision' => 0,
                'source_line_id' => $line->id,
                'source_line_revision' => 0,
                'source_amount' => '100.00',
                'source_annulled' => false,
                'reduction_amount' => '30.00',
            ]],
            'destination_plans' => [[
                'copied_from_origin_key' => $expense->originKey(),
                'supplier_id' => null,
                'description' => $expense->description,
                'notes' => null,
                'estimate_lines' => [['amount' => '30.00', 'note' => $line->note]],
            ]],
        ], (string) Str::uuid());
    }

    return compact('actor', 'company', 'source', 'destination', 'project', 'expense', 'line');
}

function confirmDeferralChange(array $fixture, array $input, ?string $operationId = null): ProjectDeferral
{
    $action = app(ChangeProjectDeferral::class);
    $preview = $action->preview($fixture['actor'], $fixture['project']->refresh(), $fixture['source']->refresh(), $fixture['destination']->refresh(), $input);

    return $action->execute(
        $fixture['actor'],
        $fixture['project']->refresh(),
        $fixture['source']->refresh(),
        $fixture['destination']->refresh(),
        $input,
        'Cambio operativo motivato',
        $operationId ?? (string) Str::uuid(),
        $preview['project_revision'],
        $preview['fingerprint'],
    );
}

it('supports carryover to none idempotently and marks affected Drafts to realign', function (): void {
    $fixture = liveDeferralFixture();
    $draft = app(InitializeProposal::class)->execute($fixture['actor'], $fixture['company'], $fixture['destination'], (string) Str::uuid());
    $operationId = (string) Str::uuid();
    $changed = confirmDeferralChange($fixture, ['mode' => 'none'], $operationId);
    $event = AuditEvent::query()->where('operation_id', $operationId)->sole();
    $retry = app(ChangeProjectDeferral::class)->execute(
        $fixture['actor'], $fixture['project']->refresh(), $fixture['source']->refresh(), $fixture['destination']->refresh(),
        ['mode' => 'none'], 'Cambio operativo motivato', $operationId, 0, str_repeat('x', 64),
    );

    expect($changed->mode->value)->toBe('none')
        ->and($changed->carryover_amount)->toBe('0.00')
        ->and($retry->is($changed))->toBeTrue()
        ->and($draft->items()->where('project_id', $fixture['project']->id)->sole()->readiness_state->value)->toBe('to_realign')
        ->and($event->eventType())->toBe(AuditEventType::ProjectDeferralChanged)
        ->and($event->actor_id)->toBe($fixture['actor']->id)
        ->and($event->affected_exercise_ids)->toBe([$fixture['source']->id, $fixture['destination']->id])
        ->and(data_get($event->previous_value, 'mode'))->toBe('carryover')
        ->and(data_get($event->new_value, 'mode'))->toBe('none')
        ->and($event->allocated_impact_by_exercise[(string) $fixture['destination']->id])->toBe('-50.00')
        ->and($event->reason)->toBe('Cambio operativo motivato');

    $this->actingAs($fixture['actor']);
    Filament::setTenant($fixture['company']);
    Livewire::withQueryParams(['project' => $fixture['project']->id])
        ->test(CompanyAudit::class)
        ->assertSee('Rinvio progetto modificato')
        ->assertTableColumnStateSet('previous_value', 'Modalità: Riporto · Riporto: € 50.00 · Riprogrammato: € 0.00 · Esercizio origine: — · Esercizio destinazione: —', $event)
        ->assertTableColumnStateSet('new_value', 'Modalità: Nessuna · Riporto: € 0.00 · Riprogrammato: € 0.00 · Esercizio origine: '.$fixture['source']->id.' · Esercizio destinazione: '.$fixture['destination']->id, $event);
});

it('supports carryover to reprogramming with deterministic generated destination allocation', function (): void {
    $fixture = liveDeferralFixture();
    $changed = confirmDeferralChange($fixture, [
        'mode' => 'reprogramming',
        'source_estimate_reductions' => [['source_line_id' => $fixture['line']->id, 'reduction_amount' => '30.00']],
    ]);

    $created = Expense::query()->where('exercise_id', $fixture['destination']->id)->sole();
    expect($changed->mode->value)->toBe('reprogramming')
        ->and($changed->carryover_amount)->toBe('0.00')
        ->and($changed->reprogrammed_amount)->toBe('30.00')
        ->and($fixture['line']->refresh()->amount)->toBe('70.00')
        ->and($created->copied_from_origin_key)->toBe($fixture['expense']->originKey())
        ->and($created->lines()->where('type', 'estimate')->sole()->amount)->toBe('30.00')
        ->and($created->lines()->where('type', 'actual')->count())->toBe(0);
});

it('reverses reprogramming to none exactly while preserving independent allocation and Actuals', function (): void {
    $fixture = liveDeferralFixture('reprogramming');
    $effects = ProjectDeferral::query()->sole()->reprogramming_effects;
    $generatedLineId = data_get($effects, 'destination_expenses.0.estimate_lines.0.expense_line_id');
    $independent = Expense::factory()->forExercise($fixture['destination'])->for($fixture['project'])->create();
    $independentEstimate = ExpenseLine::factory()->for($independent)->create(['amount' => '9.00']);
    $independentActual = ExpenseLine::factory()->for($independent)->actual()->create(['amount' => '4.00']);

    $changed = confirmDeferralChange($fixture, ['mode' => 'none']);

    expect($changed->mode->value)->toBe('none')
        ->and($fixture['line']->refresh()->amount)->toBe('100.00')
        ->and(ExpenseLine::query()->findOrFail($generatedLineId)->annulled_at)->not->toBeNull()
        ->and($independentEstimate->refresh()->annulled_at)->toBeNull()
        ->and($independentActual->refresh()->annulled_at)->toBeNull();
});

it('reverses reprogramming before validating the replacement carryover against current Actuals', function (): void {
    $fixture = liveDeferralFixture('reprogramming');
    ExpenseLine::factory()->for($fixture['expense'])->actual()->create(['amount' => '50.00']);

    $changed = confirmDeferralChange($fixture, ['mode' => 'carryover', 'carryover_amount' => '30.00']);
    expect($changed->mode->value)->toBe('carryover')
        ->and($changed->carryover_amount)->toBe('30.00')
        ->and($fixture['line']->refresh()->amount)->toBe('100.00');

    $blocked = liveDeferralFixture('reprogramming');
    ExpenseLine::factory()->for($blocked['expense'])->actual()->create(['amount' => '60.00']);
    expect(fn () => app(ChangeProjectDeferral::class)->preview(
        $blocked['actor'], $blocked['project']->refresh(), $blocked['source']->refresh(), $blocked['destination']->refresh(),
        ['mode' => 'carryover', 'carryover_amount' => '30.00'],
    ))->toThrow(ValidationException::class);
});

it('blocks exact reversal after an involved line was independently modified even if restored visibly', function (): void {
    $fixture = liveDeferralFixture('reprogramming');
    $preview = app(ChangeProjectDeferral::class)->preview(
        $fixture['actor'], $fixture['project']->refresh(), $fixture['source']->refresh(), $fixture['destination']->refresh(), ['mode' => 'none'],
    );
    $fixture['line']->increment('revision');

    expect(fn () => app(ChangeProjectDeferral::class)->execute(
        $fixture['actor'], $fixture['project']->refresh(), $fixture['source']->refresh(), $fixture['destination']->refresh(),
        ['mode' => 'none'], 'Cambio motivato', (string) Str::uuid(), $preview['project_revision'], $preview['fingerprint'],
    ))->toThrow(ValidationException::class);

    expect(ProjectDeferral::query()->sole()->mode->value)->toBe('reprogramming')
        ->and($fixture['line']->refresh()->amount)->toBe('70.00');
});

it('blocks the whole reversal when an involved parent Expense was moved or reversed', function (): void {
    $moved = liveDeferralFixture('reprogramming');
    $preview = app(ChangeProjectDeferral::class)->preview($moved['actor'], $moved['project']->refresh(), $moved['source']->refresh(), $moved['destination']->refresh(), ['mode' => 'none']);
    $moved['expense']->update(['exercise_id' => $moved['destination']->id]);
    expect(fn () => app(ChangeProjectDeferral::class)->execute(
        $moved['actor'], $moved['project']->refresh(), $moved['source']->refresh(), $moved['destination']->refresh(), ['mode' => 'none'],
        'Tentativo dopo spostamento', (string) Str::uuid(), $preview['project_revision'], $preview['fingerprint'],
    ))->toThrow(ValidationException::class);
    expect(ProjectDeferral::query()->where('project_id', $moved['project']->id)->sole()->mode->value)->toBe('reprogramming')
        ->and($moved['line']->refresh()->amount)->toBe('70.00');

    $reversed = liveDeferralFixture('reprogramming');
    $preview = app(ChangeProjectDeferral::class)->preview($reversed['actor'], $reversed['project']->refresh(), $reversed['source']->refresh(), $reversed['destination']->refresh(), ['mode' => 'none']);
    $destinationExpenseId = data_get(ProjectDeferral::query()->where('project_id', $reversed['project']->id)->sole()->reprogramming_effects, 'destination_expenses.0.expense_id');
    Expense::query()->findOrFail($destinationExpenseId)->update(['reversed_at' => now()]);
    expect(fn () => app(ChangeProjectDeferral::class)->execute(
        $reversed['actor'], $reversed['project']->refresh(), $reversed['source']->refresh(), $reversed['destination']->refresh(), ['mode' => 'none'],
        'Tentativo dopo storno', (string) Str::uuid(), $preview['project_revision'], $preview['fingerprint'],
    ))->toThrow(ValidationException::class);
    expect(ProjectDeferral::query()->where('project_id', $reversed['project']->id)->sole()->mode->value)->toBe('reprogramming')
        ->and($reversed['line']->refresh()->amount)->toBe('70.00');
});

it('rejects none as a direct source, stale previews, missing authorization and non-consecutive Exercises', function (): void {
    $fixture = liveDeferralFixture();
    ProjectDeferral::query()->sole()->update(['mode' => 'none', 'carryover_amount' => '0.00', 'carryover_state' => null]);
    expect(fn () => app(ChangeProjectDeferral::class)->preview(
        $fixture['actor'], $fixture['project'], $fixture['source'], $fixture['destination'], ['mode' => 'carryover', 'carryover_amount' => '10.00'],
    ))->toThrow(ValidationException::class);

    $stale = liveDeferralFixture();
    $preview = app(ChangeProjectDeferral::class)->preview($stale['actor'], $stale['project'], $stale['source'], $stale['destination'], ['mode' => 'none']);
    $stale['project']->increment('revision');
    expect(fn () => app(ChangeProjectDeferral::class)->execute(
        $stale['actor'], $stale['project']->refresh(), $stale['source'], $stale['destination'], ['mode' => 'none'],
        'Cambio motivato', (string) Str::uuid(), $preview['project_revision'], $preview['fingerprint'],
    ))->toThrow(ValidationException::class);

    $unauthorized = User::factory()->create();
    expect(fn () => app(ChangeProjectDeferral::class)->preview(
        $unauthorized, $stale['project'], $stale['source'], $stale['destination'], ['mode' => 'none'],
    ))->toThrow(AuthorizationException::class);

    $nonConsecutive = Exercise::factory()->for($stale['company'])->create(['year' => 2029]);
    expect(fn () => app(ChangeProjectDeferral::class)->preview(
        $stale['actor'], $stale['project'], $stale['source'], $nonConsecutive, ['mode' => 'none'],
    ))->toThrow(ValidationException::class);

    $foreignCompany = Company::factory()->create();
    $foreignDestination = Exercise::factory()->for($foreignCompany)->create(['year' => 2027]);
    expect(fn () => app(ChangeProjectDeferral::class)->preview(
        $stale['actor'], $stale['project'], $stale['source'], $foreignDestination, ['mode' => 'none'],
    ))->toThrow(ValidationException::class);

    $reasoned = liveDeferralFixture();
    $reasonPreview = app(ChangeProjectDeferral::class)->preview($reasoned['actor'], $reasoned['project'], $reasoned['source'], $reasoned['destination'], ['mode' => 'none']);
    expect(fn () => app(ChangeProjectDeferral::class)->execute(
        $reasoned['actor'], $reasoned['project'], $reasoned['source'], $reasoned['destination'], ['mode' => 'none'],
        '  ', (string) Str::uuid(), $reasonPreview['project_revision'], $reasonPreview['fingerprint'],
    ))->toThrow(ValidationException::class);
});

it('rejects direct carryover to reprogramming when destination planning needs a reopen transition', function (): void {
    $fixture = liveDeferralFixture();
    ProjectTransition::factory()->forProject($fixture['project'])->create([
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2027-01-01',
        'reason' => 'Chiusura prima della destinazione',
        'created_by_id' => $fixture['actor']->id,
    ]);

    expect(fn () => app(ChangeProjectDeferral::class)->preview(
        $fixture['actor'], $fixture['project']->refresh(), $fixture['source'], $fixture['destination'], [
            'mode' => 'reprogramming',
            'source_estimate_reductions' => [['source_line_id' => $fixture['line']->id, 'reduction_amount' => '10.00']],
        ],
    ))->toThrow(ValidationException::class);

    expect(ProjectDeferral::query()->sole()->mode->value)->toBe('carryover')
        ->and($fixture['line']->refresh()->amount)->toBe('100.00');
});

it('blocks a live terminal transition while an outgoing deferral is active', function (): void {
    $fixture = liveDeferralFixture();

    expect(fn () => app(CreateProjectTransition::class)->execute($fixture['actor'], $fixture['project'], [
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2026-10-01',
        'reason' => 'Chiusura progetto',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    expect($fixture['project']->transitions()->count())->toBe(0)
        ->and(ProjectDeferral::query()->sole()->mode->value)->toBe('carryover');
});
