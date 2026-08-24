<?php

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Actions\LateCorrections\RecordLateCorrection;
use App\Domain\Company\Capability;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\LateCorrection;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{company: Company, actor: User, exercise: Exercise, expense: Expense} */
function localHistoryFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CorrectClosedExercise] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'capability' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Spesa storica locale']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    closeExerciseFixture($exercise, $actor);

    return compact('company', 'actor', 'exercise', 'expense');
}

function appendLocalHistoryCorrection(array $fixture, string $amount, string $reason): LateCorrection
{
    $fixture['exercise']->refresh();
    $fixture['expense']->refresh();

    return app(RecordLateCorrection::class)->execute($fixture['actor'], $fixture['exercise'], [
        'source_type' => 'expense',
        'source_origin_id' => $fixture['expense']->id,
        'historical_expense_id' => $fixture['expense']->id,
        'amount' => $amount,
        'reason' => $reason,
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $fixture['exercise']->revision,
        'expected_source_revision' => $fixture['expense']->revision,
        'expected_expense_revision' => $fixture['expense']->revision,
    ], (string) Str::uuid());
}

it('loads separate local histories newest first with retained evidence and materialized archived-source context', function (): void {
    $fixture = localHistoryFixture();
    $supplier = Supplier::factory()->for($fixture['company'])->create(['legal_name' => 'Fornitore storico leggibile']);
    $project = Project::factory()->for($fixture['company'])->create();

    CarbonImmutable::setTestNow('2026-08-24 09:00:00 Europe/Rome');
    $first = app(RecordLateCorrection::class)->execute($fixture['actor'], $fixture['exercise']->refresh(), [
        'source_type' => 'project',
        'source_origin_id' => $project->id,
        'historical_expense_id' => $fixture['expense']->id,
        'description' => 'Spesa tardiva del Progetto',
        'supplier_id' => $supplier->id,
        'amount' => '25.00',
        'reason' => 'Prima correzione locale',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $fixture['exercise']->revision,
        'expected_source_revision' => $project->revision,
        'expected_expense_revision' => $fixture['expense']->revision,
    ], (string) Str::uuid());
    Attachment::factory()->forExpenseLine($first->expenseLine)->create([
        'original_name' => 'correzione-conservata.pdf',
        'uploaded_by_id' => $fixture['actor']->id,
    ]);

    CarbonImmutable::setTestNow('2026-08-24 10:00:00 Europe/Rome');
    $annotation = app(RecordHistoricalErrorAnnotation::class)->execute($fixture['actor'], $fixture['exercise']->refresh(), [
        'kind' => 'supplier',
        'reason' => 'Fornitore storico annotato',
        'recorded_facts' => ['value' => 'Fornitore registrato'],
        'believed_correct_facts' => ['value' => 'Fornitore ritenuto corretto'],
        'affected_sources' => [[
            'type' => 'exercise',
            'id' => $fixture['exercise']->id,
            'revision' => $fixture['exercise']->revision,
        ]],
        'expected_exercise_revision' => $fixture['exercise']->revision,
    ], (string) Str::uuid());
    Attachment::factory()->forHistoricalErrorAnnotation($annotation)->create([
        'original_name' => 'annotazione-conservata.pdf',
        'uploaded_by_id' => $fixture['actor']->id,
    ]);

    CarbonImmutable::setTestNow('2026-08-24 11:00:00 Europe/Rome');
    $second = appendLocalHistoryCorrection($fixture, '-25.00', 'Seconda correzione locale');
    $supplier->update(['archived_at' => now()]);

    $exercise = $fixture['exercise']->fresh()->load([
        'lateCorrections',
        'historicalErrorAnnotations',
    ]);

    expect($exercise->lateCorrections->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($exercise->historicalErrorAnnotations->pluck('id')->all())->toBe([$annotation->id])
        ->and($exercise->lateCorrections->last()->supplier_context['label'])->toBe('Fornitore storico leggibile')
        ->and($exercise->lateCorrections->last()->attachments->pluck('original_name')->all())->toBe(['correzione-conservata.pdf'])
        ->and($exercise->historicalErrorAnnotations->first()->attachments->pluck('original_name')->all())->toBe(['annotazione-conservata.pdf']);
});

it('shows distinct empty histories and rejects direct cross-company reading', function (): void {
    $fixture = localHistoryFixture();
    $otherCompany = Company::factory()->create();
    $otherExercise = Exercise::factory()->for($otherCompany)->create(['year' => 2025]);
    closeExerciseFixture($otherExercise, User::factory()->create());

    $this->actingAs($fixture['actor']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($fixture['company']);

    Livewire::test(ViewExercise::class, ['record' => $fixture['exercise']->id])
        ->assertSuccessful()
        ->assertSee('Nessuna correzione tardiva.')
        ->assertSee('Nessuna annotazione storica.');

    $this->get(ExerciseResource::getUrl('view', ['record' => $otherExercise], tenant: $fixture['company']))
        ->assertNotFound();
});
