<?php

use App\Actions\LateCorrections\RecordLateCorrection;
use App\Actions\Operations\UploadAttachment;
use App\Domain\Company\Capability;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\LateCorrection;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the Italian late-correction action only on an authorized Closed Exercise', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    closeExerciseFixture($exercise, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionExists('lateCorrection')
        ->mountAction('lateCorrection')
        ->assertSchemaComponentExists('reason')
        ->assertSchemaComponentExists('notes')
        ->assertSchemaComponentExists('belongs_to_closed_exercise')
        ->assertSchemaComponentExists('evidence');
});

it('submits the correction journey and retains uploaded evidence on the generated line', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    closeExerciseFixture($exercise, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);
    $operationId = (string) Str::uuid();
    $evidenceOperationId = (string) Str::uuid();
    $data = [
        'source_type' => 'expense',
        'source_origin_id' => $expense->id,
        'historical_expense_id' => $expense->id,
        'description' => null,
        'amount' => '5.00',
        'reason' => 'Ricevuta caricata dalla Chiusura',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->refresh()->revision,
        'expected_source_revision' => $expense->revision,
        'expected_expense_revision' => $expense->revision,
        'operation_id' => $operationId,
        'evidence_operation_id' => $evidenceOperationId,
        'evidence' => UploadedFile::fake()->createWithContent('ricevuta-livewire.txt', 'contenuto'),
    ];

    $component = Livewire::test(ViewExercise::class, ['record' => $exercise->id]);
    $component->callAction('lateCorrection', data: $data)
        ->assertHasNoActionErrors()
        ->assertSee('Ricevuta caricata dalla Chiusura')
        ->assertSee('ricevuta-livewire.txt');
    $component->callAction('lateCorrection', data: $data)->assertHasNoActionErrors();

    $correction = LateCorrection::query()->where('operation_id', $operationId)->sole();
    expect($correction->expenseLine->attachments()->attached()->pluck('original_name')->all())
        ->toBe(['ricevuta-livewire.txt']);
});
it('shows generated Expense identity and description for a new late Expense', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $selectedExpense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($selectedExpense)->actual()->create(['amount' => '10.00']);
    $project = Project::factory()->for($company)->create(['initial_effective_date' => '2025-01-01']);
    closeExerciseFixture($exercise, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    $component = Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->callAction('lateCorrection', data: [
            'source_type' => 'project',
            'source_origin_id' => $project->id,
            'historical_expense_id' => $selectedExpense->id,
            'description' => 'Spesa tardiva identificabile',
            'amount' => '8.00',
            'reason' => 'Descrizione necessaria per la nuova Spesa',
            'notes' => 'Dettagli aggiuntivi della ricevuta',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $exercise->refresh()->revision,
            'expected_source_revision' => $project->revision,
            'expected_expense_revision' => $selectedExpense->revision,
            'operation_id' => (string) Str::uuid(),
        ])
        ->assertHasNoActionErrors();
    $correction = LateCorrection::query()->sole();

    expect($correction->reason)->toBe('Descrizione necessaria per la nuova Spesa')
        ->and($correction->expense->notes)->toBe('Dettagli aggiuntivi della ricevuta')
        ->and($correction->expenseLine->note)->toBe('Dettagli aggiuntivi della ricevuta');
    $component->assertSee((string) $correction->expense_id)
        ->assertSee($correction->expense->description);
});
it('attaches stale Exercise, source and selected Expense errors to visible fields and refreshes revisions', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    closeExerciseFixture($exercise, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    $exerciseRevision = $exercise->refresh()->revision;
    $exercise->increment('revision');
    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->callAction('lateCorrection', data: [
            'source_type' => 'expense',
            'source_origin_id' => $expense->id,
            'historical_expense_id' => $expense->id,
            'amount' => '1.00',
            'reason' => 'Esercizio non aggiornato',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $exerciseRevision,
            'expected_source_revision' => $expense->revision,
            'expected_expense_revision' => $expense->revision,
            'operation_id' => (string) Str::uuid(),
        ])
        ->assertHasErrors(['source_type'])
        ->assertSchemaComponentStateSet('expected_exercise_revision', $exercise->refresh()->revision);

    $sourceRevision = $expense->refresh()->revision;
    $expense->increment('revision');
    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->callAction('lateCorrection', data: [
            'source_type' => 'expense',
            'source_origin_id' => $expense->id,
            'historical_expense_id' => $expense->id,
            'amount' => '1.00',
            'reason' => 'Sorgente non aggiornata',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $exercise->refresh()->revision,
            'expected_source_revision' => $sourceRevision,
            'expected_expense_revision' => $expense->revision,
            'operation_id' => (string) Str::uuid(),
        ])
        ->assertHasErrors(['source_origin_id'])
        ->assertSchemaComponentStateSet('expected_source_revision', $expense->refresh()->revision);

    $project = Project::factory()->for($company)->create(['initial_effective_date' => '2025-01-01']);
    $selectedRevision = $expense->refresh()->revision;
    $expense->increment('revision');
    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->callAction('lateCorrection', data: [
            'source_type' => 'project',
            'source_origin_id' => $project->id,
            'historical_expense_id' => $expense->id,
            'description' => 'Spesa selezionata non aggiornata',
            'amount' => '1.00',
            'reason' => 'Spesa non aggiornata',
            'belongs_to_closed_exercise' => true,
            'expected_exercise_revision' => $exercise->refresh()->revision,
            'expected_source_revision' => $project->revision,
            'expected_expense_revision' => $selectedRevision,
            'operation_id' => (string) Str::uuid(),
        ])
        ->assertHasErrors(['historical_expense_id'])
        ->assertSchemaComponentStateSet('expected_expense_revision', $expense->refresh()->revision);

    expect(LateCorrection::query()->count())->toBe(0);
});

it('does not expose late correction to Open Exercises or viewers', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::View]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);
    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionHidden('lateCorrection');
});

it('offers only Project and Contract sources belonging to the Closed Exercise historical context', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $historicalProject = Project::factory()->for($company)->create(['initial_effective_date' => '2025-01-01']);
    $futureProject = Project::factory()->for($company)->create(['initial_effective_date' => '2026-01-01']);
    $historicalContract = Contract::factory()->for($company)->create(['contractual_start_date' => '2025-01-01']);
    $futureContract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01']);
    closeExerciseFixture($exercise, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->mountAction('lateCorrection')
        ->fillForm(['source_type' => 'project'])
        ->assertSchemaComponentExists('source_origin_id', checkComponentUsing: function (Select $component) use ($historicalProject, $futureProject): bool {
            $options = $component->getOptions();

            return isset($options[$historicalProject->id]) && ! isset($options[$futureProject->id]);
        });

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->mountAction('lateCorrection')
        ->fillForm(['source_type' => 'contract'])
        ->assertSchemaComponentExists('source_origin_id', checkComponentUsing: function (Select $component) use ($historicalContract, $futureContract): bool {
            $options = $component->getOptions();

            return isset($options[$historicalContract->id]) && ! isset($options[$futureContract->id]);
        });
});
it('permits retained evidence only on a generated correction line', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $historicalLine = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    $snapshot = closeExerciseFixture($exercise, $actor);
    $correction = app(RecordLateCorrection::class)->execute($actor, $exercise, [
        'source_type' => 'expense',
        'source_origin_id' => $expense->id,
        'source_origin_key' => $expense->originKey(),
        'source_label' => $expense->description,
        'owner_context' => ['container' => 'autonomous'],
        'historical_expense_id' => $expense->id,
        'amount' => '5.00',
        'reason' => 'Evidenza ricevuta tardivamente',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->revision,
        'expected_source_revision' => $expense->revision,
        'expected_expense_revision' => $expense->revision,
    ], (string) Str::uuid());
    expect($correction->expenseLine->lateCorrection()->exists())->toBeTrue()
        ->and($actor->hasCapability($company, Capability::CorrectClosedExercise))->toBeTrue();

    $attachment = app(UploadAttachment::class)->execute(
        $actor,
        $correction->expenseLine,
        UploadedFile::fake()->createWithContent('ricevuta.txt', 'contenuto'),
        (string) Str::uuid(),
    );

    expect($attachment->expense_line_id)->toBe($correction->expense_line_id)
        ->and($actor->can('update', $attachment))->toBeFalse()
        ->and(fn () => app(UploadAttachment::class)->execute(
            $actor,
            $historicalLine,
            UploadedFile::fake()->createWithContent('ordinario.txt', 'contenuto'),
            (string) Str::uuid(),
        ))->toThrow(AuthorizationException::class)
        ->and($snapshot->refresh()->total_closing_actual)->toBe('10.00');
});

it('rejects altered-owner and cross-company retained-evidence retries', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $historicalLine = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);
    closeExerciseFixture($exercise, $actor);
    $correction = app(RecordLateCorrection::class)->execute($actor, $exercise, [
        'source_type' => 'expense',
        'source_origin_id' => $expense->id,
        'source_origin_key' => $expense->originKey(),
        'source_label' => $expense->description,
        'owner_context' => ['container' => 'autonomous'],
        'historical_expense_id' => $expense->id,
        'amount' => '5.00',
        'reason' => 'Evidenza di retry',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->refresh()->revision,
        'expected_source_revision' => $expense->revision,
        'expected_expense_revision' => $expense->revision,
    ], (string) Str::uuid());

    $alteredOperation = (string) Str::uuid();
    $altered = app(UploadAttachment::class)->execute(
        $actor,
        $correction->expenseLine,
        UploadedFile::fake()->createWithContent('alterato.txt', 'contenuto'),
        $alteredOperation,
    );
    $altered->update(['expense_line_id' => $historicalLine->id]);
    expect(fn () => app(UploadAttachment::class)->execute(
        $actor,
        $correction->expenseLine,
        UploadedFile::fake()->createWithContent('retry.txt', 'contenuto'),
        $alteredOperation,
    ))->toThrow(ValidationException::class);

    $otherCompany = Company::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $otherCompany->id,
        'user_id' => $actor->id,
        'capability' => Capability::CorrectClosedExercise,
    ]);
    $otherExercise = Exercise::factory()->for($otherCompany)->create(['year' => 2025]);
    $otherExpense = Expense::factory()->forExercise($otherExercise)->create();
    $otherLine = ExpenseLine::factory()->for($otherExpense)->actual()->create(['amount' => '10.00']);
    closeExerciseFixture($otherExercise, $actor);
    $otherCorrection = app(RecordLateCorrection::class)->execute($actor, $otherExercise, [
        'source_type' => 'expense',
        'source_origin_id' => $otherExpense->id,
        'source_origin_key' => $otherExpense->originKey(),
        'source_label' => $otherExpense->description,
        'owner_context' => ['container' => 'autonomous'],
        'historical_expense_id' => $otherExpense->id,
        'amount' => '4.00',
        'reason' => 'Secondo contesto',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $otherExercise->refresh()->revision,
        'expected_source_revision' => $otherExpense->revision,
        'expected_expense_revision' => $otherExpense->revision,
    ], (string) Str::uuid());
    $crossCompanyOperation = (string) Str::uuid();
    app(UploadAttachment::class)->execute(
        $actor,
        $correction->expenseLine,
        UploadedFile::fake()->createWithContent('azienda-a.txt', 'contenuto'),
        $crossCompanyOperation,
    );

    expect(fn () => app(UploadAttachment::class)->execute(
        $actor,
        $otherCorrection->expenseLine,
        UploadedFile::fake()->createWithContent('azienda-b.txt', 'contenuto'),
        $crossCompanyOperation,
    ))->toThrow(ValidationException::class)
        ->and($otherLine->refresh()->amount)->toBe('10.00');
});
