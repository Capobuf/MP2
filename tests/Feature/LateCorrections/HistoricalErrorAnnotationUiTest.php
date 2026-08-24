<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the Italian zero-impact annotation journey only for an authorized Closed Exercise', function (): void {
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
    closeExerciseFixture($exercise, $actor);

    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionExists('historicalErrorAnnotation')
        ->mountAction('historicalErrorAnnotation')
        ->assertSchemaComponentExists('recorded_fact')
        ->assertSchemaComponentExists('believed_correct_fact')
        ->assertSchemaComponentExists('affected_source_selector')
        ->assertSchemaComponentExists('reason')
        ->assertDontSee('Dato registrato (JSON)')
        ->assertDontSee('Sorgenti interessate (JSON)')
        ->assertSee('Nessun impatto economico');
});

it('records immutable annotation details and keeps the Closed Exercise controls read-only', function (): void {
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
    closeExerciseFixture($exercise, $actor);
    $exercise->refresh();
    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);
    $operationId = (string) Str::uuid();

    $component = Livewire::test(ViewExercise::class, ['record' => $exercise->id]);
    $component->callAction('historicalErrorAnnotation', data: [
        'kind' => 'accidental_closing',
        'recorded_fact' => 'Stato registrato: Chiuso',
        'believed_correct_fact' => 'Stato corretto: Aperto',
        'affected_source_selector' => ['exercise:'.$exercise->id.':'.$exercise->revision],
        'reason' => 'Chiusura registrata accidentalmente',
        'expected_exercise_revision' => $exercise->revision,
        'operation_id' => $operationId,
    ])->assertHasNoActionErrors();

    expect(HistoricalErrorAnnotation::query()->where('operation_id', $operationId)->sole()->kind->value)->toBe('accidental_closing');
    $component->assertActionExists('historicalErrorAnnotation')->assertSee('Nessun impatto economico');
});

it('does not expose annotation action on an Open Exercise or to viewers without correction capability', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
        'capability' => Capability::View,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);

    $this->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    Livewire::test(ViewExercise::class, ['record' => $exercise->id])
        ->assertSuccessful()
        ->assertActionHidden('historicalErrorAnnotation');
});
