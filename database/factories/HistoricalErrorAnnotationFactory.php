<?php

namespace Database\Factories;

use App\Domain\LateCorrections\HistoricalErrorKind;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HistoricalErrorAnnotation> */
class HistoricalErrorAnnotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'exercise_id' => fn (array $attributes): int => Exercise::factory()->create([
                'company_id' => $attributes['company_id'],
                'status' => 'closed',
            ])->id,
            'closing_snapshot_id' => fn (array $attributes): int => ClosingSnapshot::query()->firstOrCreate(
                [
                    'company_id' => $attributes['company_id'],
                    'exercise_id' => $attributes['exercise_id'],
                ],
                [
                    'company_name' => Company::query()->findOrFail($attributes['company_id'])->name,
                    'exercise_year' => Exercise::query()->findOrFail($attributes['exercise_id'])->year,
                    'closed_at' => now(),
                    'closed_by_id' => User::factory()->create()->id,
                    'initial_budget_id' => null,
                    'current_budget_id' => null,
                    'total_final_allocation' => '0.00',
                    'total_closing_actual' => '0.00',
                    'total_operational_variance' => '0.00',
                    'total_consolidated_carryover' => '0.00',
                    'accepted_warnings' => [],
                    'applied_settings' => [],
                    'next_exercise_disposition' => 'not_created',
                    'next_exercise_id' => null,
                    'operation_id' => (string) Str::uuid(),
                ],
            )->id,
            'recorded_by_id' => User::factory()->create()->id,
            'operation_id' => (string) Str::uuid(),
            'kind' => HistoricalErrorKind::CostCenter,
            'reason' => 'Motivo dell’errore storico',
            'recorded_facts_version' => 1,
            'recorded_facts' => ['id' => 1, 'label' => 'Centro registrato'],
            'believed_correct_facts_version' => 1,
            'believed_correct_facts' => ['id' => 2, 'label' => 'Centro corretto'],
            'affected_sources_version' => 1,
            'affected_sources' => fn (array $attributes): array => [[
                'type' => 'closing_snapshot',
                'id' => $attributes['closing_snapshot_id'],
                'origin_key' => 'closing_snapshot:'.$attributes['closing_snapshot_id'],
                'label' => 'Snapshot di Chiusura',
            ]],
        ];
    }

    public function forExercise(Exercise $exercise): static
    {
        return $this->state([
            'company_id' => $exercise->company_id,
            'exercise_id' => $exercise->id,
            'closing_snapshot_id' => fn (array $attributes): int => ClosingSnapshot::query()
                ->where('company_id', $attributes['company_id'])
                ->where('exercise_id', $attributes['exercise_id'])
                ->sole()
                ->id,
        ]);
    }
}
