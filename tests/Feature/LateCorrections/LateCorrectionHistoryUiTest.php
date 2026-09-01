<?php

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Actions\LateCorrections\RecordLateCorrection;
use App\Filament\Resources\Closings\Pages\ViewClosing;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('keeps Closing values distinct from both immutable local evidence collections', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::CORRECT_CLOSED_EXERCISE] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $actor,
            'permissions' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Spesa della Chiusura']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    $snapshot = closeExerciseFixture($exercise, $actor);
    $exercise->refresh();
    $expense->refresh();

    $correction = app(RecordLateCorrection::class)->execute($actor, $exercise, [
        'source_type' => 'expense',
        'source_origin_id' => $expense->id,
        'historical_expense_id' => $expense->id,
        'amount' => '15.00',
        'reason' => 'Correzione visibile dalla Chiusura',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->revision,
        'expected_source_revision' => $expense->revision,
        'expected_expense_revision' => $expense->revision,
    ], (string) Str::uuid());
    Attachment::factory()->forExpenseLine($correction->expenseLine)->create([
        'original_name' => 'evidenza-correzione.pdf',
        'uploaded_by_id' => $actor->id,
    ]);
    $exercise->refresh();
    $annotation = app(RecordHistoricalErrorAnnotation::class)->execute($actor, $exercise, [
        'kind' => 'cost_center',
        'reason' => 'Annotazione visibile dalla Chiusura',
        'recorded_facts' => ['value' => 'Centro registrato'],
        'believed_correct_facts' => ['value' => 'Centro ritenuto corretto'],
        'affected_sources' => [[
            'type' => 'closing_snapshot',
            'id' => $snapshot->id,
            'revision' => 0,
        ]],
        'expected_exercise_revision' => $exercise->revision,
    ], (string) Str::uuid());
    Attachment::factory()->forHistoricalErrorAnnotation($annotation)->create([
        'original_name' => 'evidenza-annotazione.pdf',
        'uploaded_by_id' => $actor->id,
    ]);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewClosing::class, ['record' => $snapshot->id])
        ->assertSuccessful()
        ->assertSee('Effettivo alla Chiusura')
        ->assertSee('100,00')
        ->assertSee('Correzioni Tardive')
        ->assertSee('Correzione visibile dalla Chiusura')
        ->assertSee('evidenza-correzione.pdf')
        ->assertSee('Annotazioni di Errore Storico')
        ->assertSee('Annotazione visibile dalla Chiusura')
        ->assertSee('Nessun impatto economico')
        ->assertSee('evidenza-annotazione.pdf')
        ->assertDontSee('Previsto')
        ->assertDontSee('Non Previsto')
        ->assertDontSee('Esporta');

    expect($snapshot->refresh()->total_closing_actual)->toBe('100.00');
});
