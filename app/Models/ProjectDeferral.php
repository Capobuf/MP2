<?php

namespace App\Models;

use App\Domain\Projects\ProjectDeferralMode;
use Database\Factories\ProjectDeferralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * @property ProjectDeferralMode $mode
 * @property string $carryover_amount
 * @property string|null $carryover_state
 * @property string $reprogrammed_amount
 * @property string|null $reprogramming_operation_id
 * @property array{source_lines?: list<array<string, mixed>>, destination_expenses?: list<array<string, mixed>>}|null $reprogramming_effects
 * @property Exercise $sourceExercise
 * @property Exercise $destinationExercise
 */
#[Fillable([
    'company_id',
    'project_id',
    'source_exercise_id',
    'destination_exercise_id',
    'mode',
    'carryover_amount',
    'carryover_state',
    'reprogrammed_amount',
    'reprogramming_operation_id',
    'reprogramming_effects',
])]
class ProjectDeferral extends Model
{
    /** @use HasFactory<ProjectDeferralFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $deferral): void {
            $project = Project::query()->find($deferral->project_id);
            $source = Exercise::query()->find($deferral->source_exercise_id);
            $destination = Exercise::query()->find($deferral->destination_exercise_id);

            if ($project === null || $source === null || $destination === null
                || $project->company_id !== (int) $deferral->company_id
                || $source->company_id !== (int) $deferral->company_id
                || $destination->company_id !== (int) $deferral->company_id) {
                throw ValidationException::withMessages(['deferral' => 'Progetto ed Esercizi devono appartenere alla stessa Azienda.']);
            }
            if ($destination->year !== $source->year + 1) {
                throw ValidationException::withMessages(['destination_exercise_id' => 'L’Esercizio destinazione deve essere immediatamente successivo.']);
            }
            if (! $source->isOpen() && (! $deferral->exists || $deferral->isDirty([
                'mode',
                'carryover_amount',
                'carryover_state',
                'reprogrammed_amount',
                'reprogramming_operation_id',
                'reprogramming_effects',
            ]))) {
                throw ValidationException::withMessages([
                    'deferral' => 'Il rinvio consolidato di un Esercizio Chiuso non può essere modificato.',
                ]);
            }
        });

        static::deleting(function (): never {
            throw new \LogicException('Project deferrals cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function sourceExercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'source_exercise_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function destinationExercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'destination_exercise_id');
    }

    protected function casts(): array
    {
        return [
            'mode' => ProjectDeferralMode::class,
            'carryover_amount' => 'decimal:2',
            'reprogrammed_amount' => 'decimal:2',
            'reprogramming_effects' => 'array',
        ];
    }
}
