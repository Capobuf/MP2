<?php

namespace App\Actions\LateCorrections;

use App\Domain\Company\AuditEventType;
use App\Domain\LateCorrections\HistoricalErrorKind;
use App\Models\AuditEvent;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\HistoricalErrorAnnotation;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordHistoricalErrorAnnotation
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Exercise $exercise, array $input, string $operationId): HistoricalErrorAnnotation
    {
        $normalized = [
            'kind' => ($input['kind'] ?? null) instanceof HistoricalErrorKind
                ? $input['kind']->value
                : (is_string($input['kind'] ?? null) ? trim((string) $input['kind']) : ($input['kind'] ?? null)),
            'reason' => is_string($input['reason'] ?? null) ? trim((string) $input['reason']) : ($input['reason'] ?? null),
            'recorded_facts_version' => $input['recorded_facts_version'] ?? 1,
            'recorded_facts' => $input['recorded_facts'] ?? null,
            'believed_correct_facts_version' => $input['believed_correct_facts_version'] ?? 1,
            'believed_correct_facts' => $input['believed_correct_facts'] ?? null,
            'affected_sources_version' => $input['affected_sources_version'] ?? 1,
            'affected_sources' => $input['affected_sources'] ?? null,
            'expected_exercise_revision' => $input['expected_exercise_revision'] ?? null,
            'operation_id' => $operationId,
        ];

        $validator = Validator::make($normalized, [
            'kind' => ['required', 'string', 'in:'.implode(',', array_map(fn (HistoricalErrorKind $kind): string => $kind->value, HistoricalErrorKind::cases()))],
            'reason' => ['required', 'string', 'max:65535'],
            'recorded_facts_version' => ['required', 'integer', 'in:1'],
            'recorded_facts' => ['required', 'array', 'min:1'],
            'believed_correct_facts_version' => ['required', 'integer', 'in:1'],
            'believed_correct_facts' => ['required', 'array', 'min:1'],
            'affected_sources_version' => ['required', 'integer', 'in:1'],
            'affected_sources' => ['required', 'array', 'min:1'],
            'expected_exercise_revision' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ]);
        $validator->after(function ($validator) use ($normalized): void {
            if (! is_array($normalized['affected_sources'] ?? null)) {
                return;
            }

            foreach ($normalized['affected_sources'] as $index => $source) {
                if (! is_array($source)
                    || ! isset($source['type'], $source['id'], $source['revision'])
                    || ! is_string($source['type'])
                    || ! in_array($source['type'], HistoricalErrorAnnotation::SUPPORTED_SOURCE_TYPES, true)
                    || ! is_numeric($source['id'])
                    || (int) $source['id'] < 1
                    || ! is_numeric($source['revision'])
                    || (int) $source['revision'] < 0) {
                    $validator->errors()->add("affected_sources.$index", 'La sorgente interessata deve essere un riferimento selezionato e aggiornato.');
                }
            }
        });
        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return DB::transaction(function () use ($actor, $exercise, $validated): HistoricalErrorAnnotation {
            $company = Company::query()->lockForUpdate()->findOrFail($exercise->company_id);
            $lockedExercise = Exercise::query()->lockForUpdate()->findOrFail($exercise->id);
            if ((int) $lockedExercise->company_id !== (int) $company->id) {
                throw ValidationException::withMessages([
                    'exercise_id' => 'Esercizio non disponibile per questa Azienda.',
                ]);
            }

            Gate::forUser($actor)->authorize('annotateHistoricalError', $lockedExercise);
            Gate::forUser($actor)->authorize('create', [HistoricalErrorAnnotation::class, $company]);

            $snapshot = ClosingSnapshot::query()
                ->where('company_id', $company->id)
                ->where('exercise_id', $lockedExercise->id)
                ->lockForUpdate()
                ->first();
            if ($snapshot === null) {
                throw ValidationException::withMessages([
                    'closing_snapshot' => 'La Snapshot di Chiusura canonica non è disponibile.',
                ]);
            }

            $existing = HistoricalErrorAnnotation::query()
                ->where('operation_id', $validated['operation_id'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! $this->sameOperation($existing, $lockedExercise, $snapshot, $validated)) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato per un altro contesto storico.',
                    ]);
                }

                return $existing->load(['closingSnapshot', 'recordedBy', 'attachments']);
            }

            $operationEvent = AuditEvent::query()
                ->where('operation_id', $validated['operation_id'])
                ->lockForUpdate()
                ->first();
            if ($operationEvent !== null) {
                throw ValidationException::withMessages([
                    'operation_id' => 'Identificativo operazione già utilizzato.',
                ]);
            }

            if ((int) $validated['expected_exercise_revision'] !== (int) $lockedExercise->revision) {
                throw ValidationException::withMessages([
                    'expected_exercise_revision' => 'L’Esercizio è cambiato: ricaricare il contesto prima di confermare.',
                ]);
            }

            $affectedSources = $this->lockAndValidateSources(
                $validated['affected_sources'],
                $company,
                $lockedExercise,
                $snapshot,
            );

            $annotation = HistoricalErrorAnnotation::query()->create([
                'company_id' => $company->id,
                'exercise_id' => $lockedExercise->id,
                'closing_snapshot_id' => $snapshot->id,
                'recorded_by_id' => $actor->id,
                'operation_id' => $validated['operation_id'],
                'kind' => HistoricalErrorKind::from((string) $validated['kind']),
                'reason' => $validated['reason'],
                'recorded_facts_version' => (int) $validated['recorded_facts_version'],
                'recorded_facts' => $validated['recorded_facts'],
                'believed_correct_facts_version' => (int) $validated['believed_correct_facts_version'],
                'believed_correct_facts' => $validated['believed_correct_facts'],
                'affected_sources_version' => (int) $validated['affected_sources_version'],
                'affected_sources' => $affectedSources,
            ]);

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::HistoricalErrorAnnotationRecorded,
                'subject_type' => HistoricalErrorAnnotation::class,
                'subject_id' => $annotation->id,
                'affected_exercise_ids' => [$lockedExercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => [
                    'kind' => $this->kindValue($annotation),
                    'recorded_facts_version' => $annotation->recorded_facts_version,
                    'recorded_facts' => $annotation->recorded_facts,
                    'believed_correct_facts_version' => $annotation->believed_correct_facts_version,
                    'believed_correct_facts' => $annotation->believed_correct_facts,
                    'affected_sources_version' => $annotation->affected_sources_version,
                    'affected_sources' => $annotation->affected_sources,
                    'closing_snapshot_id' => $snapshot->id,
                    'economic_impact' => '0.00',
                    'reason' => $annotation->reason,
                ],
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
                'reason' => $annotation->reason,
                'reference_type' => HistoricalErrorAnnotation::class,
                'reference_id' => $annotation->id,
            ]);

            return $annotation->load(['closingSnapshot', 'recordedBy', 'attachments']);
        });
    }

    /** @param array<string, mixed> $validated */
    private function sameOperation(HistoricalErrorAnnotation $existing, Exercise $exercise, ClosingSnapshot $snapshot, array $validated): bool
    {
        $sourceIdentity = static fn (mixed $source): string => is_array($source)
            ? (string) ($source['type'] ?? '').':'.(int) ($source['id'] ?? 0)
            : '';
        $submittedSources = array_map($sourceIdentity, $validated['affected_sources']);
        $existingAffectedSources = $existing->getAttribute('affected_sources');
        $existingSources = is_array($existingAffectedSources) ? array_map($sourceIdentity, $existingAffectedSources) : [];
        sort($submittedSources);
        sort($existingSources);

        return (int) $existing->company_id === (int) $exercise->company_id
            && (int) $existing->exercise_id === (int) $exercise->id
            && (int) $existing->closing_snapshot_id === (int) $snapshot->id
            && $this->kindValue($existing) === $validated['kind']
            && $existing->reason === $validated['reason']
            && (int) $existing->recorded_facts_version === (int) $validated['recorded_facts_version']
            && $existing->recorded_facts == $validated['recorded_facts']
            && (int) $existing->believed_correct_facts_version === (int) $validated['believed_correct_facts_version']
            && $existing->believed_correct_facts == $validated['believed_correct_facts']
            && (int) $existing->affected_sources_version === (int) $validated['affected_sources_version']
            && $existingSources === $submittedSources;
    }

    private function kindValue(HistoricalErrorAnnotation $annotation): string
    {
        $kind = $annotation->getAttribute('kind');
        if (! $kind instanceof HistoricalErrorKind) {
            throw new \UnexpectedValueException('Invalid persisted historical error kind.');
        }

        return $kind->value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return list<array{type: string, id: int, origin_key: string, label: string}>
     */
    private function lockAndValidateSources(array $sources, Company $company, Exercise $exercise, ClosingSnapshot $snapshot): array
    {
        $ordered = collect($sources)
            ->map(fn (array $source, int|string $index): array => ['index' => $index, 'source' => $source])
            ->sortBy([
                fn (array $entry): int => array_search($entry['source']['type'], HistoricalErrorAnnotation::SUPPORTED_SOURCE_TYPES, true),
                fn (array $entry): int => (int) $entry['source']['id'],
            ]);
        $result = [];

        foreach ($ordered as $entry) {
            $index = $entry['index'];
            $source = $entry['source'];
            $type = (string) $source['type'];
            $id = (int) $source['id'];
            $model = match ($type) {
                'expense' => Expense::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->lockForUpdate()->find($id),
                'project' => Project::query()->where('company_id', $company->id)->lockForUpdate()->find($id),
                'contract' => Contract::query()->where('company_id', $company->id)->lockForUpdate()->find($id),
                'supplier' => Supplier::query()->where('company_id', $company->id)->lockForUpdate()->find($id),
                'cost_center' => CostCenter::query()->where('company_id', $company->id)->lockForUpdate()->find($id),
                'exercise' => Exercise::query()->where('company_id', $company->id)->lockForUpdate()->find($id),
                'closing_snapshot' => ClosingSnapshot::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->lockForUpdate()->find($id),
                default => null,
            };

            if ($model === null) {
                throw ValidationException::withMessages([
                    "affected_sources.$index" => 'La sorgente interessata non appartiene al contesto storico selezionato.',
                ]);
            }
            if (! $this->isHistoricalSource($type, $model, $exercise, $snapshot)) {
                throw ValidationException::withMessages([
                    "affected_sources.$index" => 'La sorgente interessata non è disponibile nell’Esercizio Chiuso selezionato.',
                ]);
            }
            $revision = $this->sourceRevision($type, $model);
            if ((int) $source['revision'] !== $revision) {
                throw ValidationException::withMessages([
                    "affected_sources.$index" => 'La sorgente storica è cambiata: ricaricare il contesto prima di confermare.',
                ]);
            }

            $result[] = [
                'type' => $type,
                'id' => $id,
                'origin_key' => $this->sourceOriginKey($type, $model),
                'label' => $this->sourceLabel($type, $model),
            ];
        }

        return $result;
    }

    private function sourceRevision(string $type, object $model): int
    {
        if ($type === 'closing_snapshot') {
            return 0;
        }
        $revision = $model->getAttribute('revision');
        if ($revision !== null) {
            return (int) $revision;
        }
        $updatedAt = $model->getAttribute('updated_at');

        return $updatedAt instanceof \DateTimeInterface ? $updatedAt->getTimestamp() : 0;
    }

    private function sourceOriginKey(string $type, object $model): string
    {
        return match ($type) {
            'exercise' => 'exercise:'.$model->getKey(),
            'closing_snapshot' => 'closing_snapshot:'.$model->getKey(),
            'supplier' => 'supplier:'.$model->getKey(),
            'cost_center' => 'cost_center:'.$model->getKey(),
            default => $model->originKey(),
        };
    }

    private function sourceLabel(string $type, object $model): string
    {
        return match ($type) {
            'expense' => (string) $model->description,
            'project' => (string) $model->title,
            'contract' => (string) $model->title,
            'supplier' => (string) $model->legal_name.($model->isArchived() ? ' · Archiviato' : ''),
            'cost_center' => (string) $model->name.($model->isArchived() ? ' · Archiviato' : ''),
            'exercise' => 'Esercizio '.$model->year,
            'closing_snapshot' => 'Snapshot di Chiusura '.$model->exercise_year,
            default => throw new \UnexpectedValueException('Unsupported historical source type.'),
        };
    }

    private function isHistoricalSource(string $type, object $model, Exercise $exercise, ClosingSnapshot $snapshot): bool
    {
        if ($type === 'exercise') {
            return (int) $model->id === (int) $exercise->id;
        }
        if ($type === 'closing_snapshot') {
            return (int) $model->id === (int) $snapshot->id;
        }
        $endOfYear = $exercise->year.'-12-31';

        return match ($type) {
            'expense' => (int) $model->exercise_id === (int) $exercise->id
                && $model->project_id === null
                && $model->contract_id === null,
            'project' => Expense::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)->where('project_id', $model->id)->exists()
                || $model->classifications()->where('exercise_id', $exercise->id)->exists()
                || $model->transitions()->whereDate('effective_date', '<=', $endOfYear)->exists()
                || $model->initialEffectiveDate()->toDateString() <= $endOfYear,
            'contract' => Expense::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)->where('contract_id', $model->id)->exists()
                || $model->classifications()->where('exercise_id', $exercise->id)->exists()
                || $model->lifecycleFacts()->whereDate('declared_contractual_date', '<=', $endOfYear)->exists()
                || $model->conditions()->whereDate('valid_from', '<=', $endOfYear)->exists()
                || $model->contractualStartDate()->toDateString() <= $endOfYear,
            'supplier' => Expense::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)->where('supplier_id', $model->id)->exists()
                || Contract::query()->where('company_id', $exercise->company_id)->where('supplier_id', $model->id)->exists(),
            'cost_center' => Expense::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)->where('direct_cost_center_id', $model->id)->exists()
                || ProjectExerciseClassification::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)->where('cost_center_id', $model->id)->exists()
                || ContractExerciseClassification::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)->where('cost_center_id', $model->id)->exists(),
            default => false,
        };
    }
}
