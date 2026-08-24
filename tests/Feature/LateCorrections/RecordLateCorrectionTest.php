<?php

use App\Actions\LateCorrections\RecordLateCorrection;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\LateCorrection;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeLateCorrectionFixture(bool $withCapability = true): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    if ($withCapability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'capability' => Capability::CorrectClosedExercise,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Spesa storica']);
    $line = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    $snapshot = closeExerciseFixture($exercise, $actor);
    $exercise->refresh();

    return compact('company', 'actor', 'exercise', 'expense', 'line', 'snapshot');
}

function lateCorrectionInput(Exercise $exercise, Expense $source, array $overrides = []): array
{
    $exercise->refresh();
    $source->refresh();

    return [
        'source_type' => 'expense',
        'source_origin_id' => $source->id,
        'source_origin_key' => $source->originKey(),
        'source_label' => $source->description,
        'owner_context' => ['container' => 'autonomous'],
        'historical_expense_id' => $source->id,
        'description' => null,
        'amount' => '25.00',
        'reason' => 'Fattura ricevuta dopo la Chiusura',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->revision,
        'expected_source_revision' => $source->revision,
        'expected_expense_revision' => $source->revision,
        ...$overrides,
    ];
}

it('appends one Actual to a compatible historical Expense and returns the same receipt on retry', function (): void {
    $fixture = makeLateCorrectionFixture();
    $operationId = (string) Str::uuid();
    $input = lateCorrectionInput($fixture['exercise'], $fixture['expense']);

    $correction = app(RecordLateCorrection::class)->execute($fixture['actor'], $fixture['exercise'], $input, $operationId);
    $retry = app(RecordLateCorrection::class)->execute($fixture['actor'], $fixture['exercise'], $input, $operationId);

    expect($retry->id)->toBe($correction->id)
        ->and($fixture['expense']->refresh()->lines()->count())->toBe(2)
        ->and($fixture['expense']->actual())->toBe('125.00')
        ->and($fixture['line']->refresh()->amount)->toBe('100.00')
        ->and($fixture['snapshot']->refresh()->total_closing_actual)->toBe('100.00')
        ->and(LateCorrection::query()->where('operation_id', $operationId)->count())->toBe(1);
});
it('rejects reusing a correction operation for another Closed Exercise in the same Company', function (): void {
    $fixture = makeLateCorrectionFixture();
    $otherExercise = Exercise::factory()->for($fixture['company'])->create(['year' => 2026]);
    $otherExpense = Expense::factory()->forExercise($otherExercise)->create();
    ExpenseLine::factory()->for($otherExpense)->actual()->create(['amount' => '40.00']);
    $otherSnapshot = closeExerciseFixture($otherExercise, $fixture['actor']);
    $operationId = (string) Str::uuid();

    app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        lateCorrectionInput($fixture['exercise'], $fixture['expense']),
        $operationId,
    );

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $otherExercise,
        lateCorrectionInput($otherExercise, $otherExpense),
        $operationId,
    ))->toThrow(ValidationException::class)
        ->and(LateCorrection::query()->where('exercise_id', $otherExercise->id)->count())->toBe(0)
        ->and($otherSnapshot->refresh()->total_closing_actual)->toBe('40.00');
});

it('appends a negative compensating Actual and keeps the original line unchanged', function (): void {
    $fixture = makeLateCorrectionFixture();
    $first = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        lateCorrectionInput($fixture['exercise'], $fixture['expense'], ['amount' => '25.00']),
        (string) Str::uuid(),
    );
    $fixture['exercise']->refresh();

    $second = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        lateCorrectionInput($fixture['exercise'], $fixture['expense'], [
            'amount' => '-25.00',
            'reason' => 'Compensazione della correzione precedente',
            'original_expense_line_id' => $fixture['line']->id,
            'expected_exercise_revision' => $fixture['exercise']->revision,
        ]),
        (string) Str::uuid(),
    );

    expect($second->original_expense_line_id)->toBe($fixture['line']->id)
        ->and($fixture['expense']->refresh()->lines()->count())->toBe(3)
        ->and($fixture['expense']->actual())->toBe('100.00')
        ->and($fixture['line']->refresh()->amount)->toBe('100.00')
        ->and($first->expense_line_id)->not->toBe($second->expense_line_id);
});

it('rejects an Open Exercise and an actor without the correction capability', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $actor,
        $exercise,
        lateCorrectionInput($exercise, $expense),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(LateCorrection::query()->count())->toBe(0);
});

it('rejects a false closed-year declaration before persistence', function (): void {
    $fixture = makeLateCorrectionFixture();

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        lateCorrectionInput($fixture['exercise'], $fixture['expense'], ['belongs_to_closed_exercise' => false]),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(LateCorrection::query()->count())->toBe(0)
        ->and(ExpenseLine::query()->where('expense_id', $fixture['expense']->id)->count())->toBe(1);
});

it('creates a new same-context manual Expense for an incompatible selected Expense and retains an archived Supplier', function (): void {
    $fixture = makeLateCorrectionFixture();
    $project = Project::factory()->for($fixture['company'])->create();
    $supplier = Supplier::factory()->for($fixture['company'])->archived()->create();

    $correction = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        [
            'source_type' => 'project',
            'source_origin_id' => $project->id,
            'source_origin_key' => 'fabricated-key',
            'source_label' => 'Etichetta alterata',
            'owner_context' => ['container' => 'autonomous'],
            'historical_expense_id' => $fixture['expense']->id,
            'description' => 'Nuova correzione Progetto',
            'supplier_id' => $supplier->id,
            'amount' => '14.50',
            'reason' => 'Spesa incompatibile selezionata',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $fixture['exercise']->revision,
            'expected_source_revision' => $project->revision,
            'expected_expense_revision' => $fixture['expense']->revision,
        ],
        (string) Str::uuid(),
    );

    expect($correction->expense_id)->not->toBe($fixture['expense']->id)
        ->and($correction->expense->project_id)->toBe($project->id)
        ->and($correction->expense->origin)->toBe('manual')
        ->and($correction->expense->supplier_id)->toBe($supplier->id)
        ->and($correction->source_origin_key)->toBe($project->originKey())
        ->and($correction->source_label)->toBe($project->title);
});
it('increments the affected Project and Contract source revisions and rejects old source tokens', function (): void {
    $fixture = makeLateCorrectionFixture();
    $project = Project::factory()->for($fixture['company'])->create();
    $projectRevision = $project->revision;

    $projectCorrection = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        [
            'source_type' => 'project',
            'source_origin_id' => $project->id,
            'historical_expense_id' => $fixture['expense']->id,
            'description' => 'Correzione Progetto',
            'amount' => '14.50',
            'reason' => 'Aggiornamento del Progetto storico',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $fixture['exercise']->revision,
            'expected_source_revision' => $projectRevision,
            'expected_expense_revision' => $fixture['expense']->revision,
        ],
        (string) Str::uuid(),
    );
    $fixture['exercise']->refresh();

    $contract = Contract::factory()->for($fixture['company'])->create();
    $contractRevision = $contract->revision;
    $contractCorrection = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        [
            'source_type' => 'contract',
            'source_origin_id' => $contract->id,
            'description' => 'Correzione Contratto',
            'amount' => '9.25',
            'reason' => 'Aggiornamento del Contratto storico',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $fixture['exercise']->revision,
            'expected_source_revision' => $contractRevision,
        ],
        (string) Str::uuid(),
    );

    expect($projectCorrection->expense->project_id)->toBe($project->id)
        ->and($project->refresh()->revision)->toBe($projectRevision + 1)
        ->and($contractCorrection->expense->contract_id)->toBe($contract->id)
        ->and($contract->refresh()->revision)->toBe($contractRevision + 1);

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise']->refresh(),
        [
            'source_type' => 'contract',
            'source_origin_id' => $contract->id,
            'description' => 'Seconda correzione',
            'amount' => '1.00',
            'reason' => 'Token superato',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $fixture['exercise']->revision,
            'expected_source_revision' => $contractRevision,
        ],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);
});

it('creates a new same-context autonomous Expense when no historical Expense is selected', function (): void {
    $fixture = makeLateCorrectionFixture();
    $correction = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        lateCorrectionInput($fixture['exercise'], $fixture['expense'], [
            'historical_expense_id' => null,
            'description' => 'Spesa tardiva autonoma distinta',
            'amount' => '7.25',
            'expected_expense_revision' => null,
        ]),
        (string) Str::uuid(),
    );

    expect($correction->expense_id)->not->toBe($fixture['expense']->id)
        ->and($correction->expense->project_id)->toBeNull()
        ->and($correction->expense->contract_id)->toBeNull()
        ->and($correction->expense->origin)->toBe('manual')
        ->and($correction->expense->lines()->where('type', 'actual')->count())->toBe(1);
});

it('rejects stale source and selected Expense revisions without persistence', function (): void {
    $fixture = makeLateCorrectionFixture();
    $project = Project::factory()->for($fixture['company'])->create();
    $projectRevision = $project->revision;
    $selectedRevision = $fixture['expense']->revision;
    $project->increment('revision');
    $fixture['expense']->increment('revision');

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        [
            'source_type' => 'project',
            'source_origin_id' => $project->id,
            'source_origin_key' => $project->originKey(),
            'source_label' => $project->title,
            'owner_context' => ['container' => 'project', 'project_id' => $project->id],
            'historical_expense_id' => $fixture['expense']->id,
            'description' => 'Non deve essere scritta',
            'amount' => '3.00',
            'reason' => 'Contesto non aggiornato',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $fixture['exercise']->revision,
            'expected_source_revision' => $projectRevision,
            'expected_expense_revision' => $selectedRevision,
        ],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(LateCorrection::query()->count())->toBe(0)
        ->and($fixture['expense']->lines()->count())->toBe(1);
    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        [
            'source_type' => 'project',
            'source_origin_id' => $project->id,
            'source_origin_key' => $project->originKey(),
            'source_label' => $project->title,
            'owner_context' => ['container' => 'project', 'project_id' => $project->id],
            'historical_expense_id' => $fixture['expense']->id,
            'description' => 'Non deve essere scritta',
            'amount' => '3.00',
            'reason' => 'Spesa non aggiornata',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $fixture['exercise']->revision,
            'expected_source_revision' => $project->revision,
            'expected_expense_revision' => $selectedRevision,
        ],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);
});

it('rejects omitted revision tokens on the corresponding visible context fields', function (): void {
    foreach ([
        ['expected_exercise_revision', 'source_type'],
        ['expected_source_revision', 'source_origin_id'],
        ['expected_expense_revision', 'historical_expense_id'],
    ] as [$missingToken, $visibleField]) {
        $fixture = makeLateCorrectionFixture();
        $input = lateCorrectionInput($fixture['exercise'], $fixture['expense']);
        unset($input[$missingToken]);
        $exception = null;

        try {
            app(RecordLateCorrection::class)->execute(
                $fixture['actor'],
                $fixture['exercise'],
                $input,
                (string) Str::uuid(),
            );
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(ValidationException::class)
            ->and($exception?->errors())->toHaveKey($visibleField)
            ->and(LateCorrection::query()->count())->toBe(0);
    }
});

it('rejects foreign and absent historical sources without persistence', function (): void {
    $fixture = makeLateCorrectionFixture();
    $foreignCompany = Company::factory()->create();
    $foreignSource = Expense::factory()->for($foreignCompany)->create();
    $base = lateCorrectionInput($fixture['exercise'], $fixture['expense']);

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        [...$base, 'source_origin_id' => $foreignSource->id, 'source_origin_key' => $foreignSource->originKey()],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordLateCorrection::class)->execute(
            $fixture['actor'],
            $fixture['exercise'],
            [...$base, 'source_origin_id' => 999999, 'source_origin_key' => 'expense:999999'],
            (string) Str::uuid(),
        ))->toThrow(ValidationException::class)
        ->and(LateCorrection::query()->count())->toBe(0);
});

it('rejects a current-year correction on an Open Exercise even with the capability', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::CorrectClosedExercise,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $actor,
        $exercise,
        lateCorrectionInput($exercise, $expense),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(LateCorrection::query()->count())->toBe(0);
});

it('rejects a Closed Exercise without its canonical Snapshot before writing', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::CorrectClosedExercise,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['status' => 'closed']);
    $expense = Expense::factory()->forExercise($exercise)->create();

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $actor,
        $exercise,
        lateCorrectionInput($exercise, $expense),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(LateCorrection::query()->count())->toBe(0)
        ->and($expense->lines()->count())->toBe(0);
});

it('rolls back the generated Expense, Actual and correction when audit persistence fails', function (): void {
    $fixture = makeLateCorrectionFixture();
    $beforeExpenses = Expense::query()->count();
    AuditEvent::creating(fn (): never => throw new RuntimeException('Forced correction audit failure'));

    expect(fn () => app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        lateCorrectionInput($fixture['exercise'], $fixture['expense'], [
            'historical_expense_id' => null,
            'description' => 'Rollback tardivo',
        ]),
        (string) Str::uuid(),
    ))->toThrow(RuntimeException::class)
        ->and(LateCorrection::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe($beforeExpenses)
        ->and($fixture['expense']->lines()->count())->toBe(1);

    AuditEvent::flushEventListeners();
});
