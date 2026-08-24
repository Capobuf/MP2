<?php

namespace App\Models;

use App\Domain\LateCorrections\HistoricalErrorKind;
use Database\Factories\HistoricalErrorAnnotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id',
    'exercise_id',
    'closing_snapshot_id',
    'recorded_by_id',
    'operation_id',
    'kind',
    'reason',
    'recorded_facts_version',
    'recorded_facts',
    'believed_correct_facts_version',
    'believed_correct_facts',
    'affected_sources_version',
    'affected_sources',
])]
class HistoricalErrorAnnotation extends Model
{
    /** @use HasFactory<HistoricalErrorAnnotationFactory> */
    use HasFactory;

    /** @var list<string> */
    public const SUPPORTED_SOURCE_TYPES = [
        'expense',
        'project',
        'contract',
        'supplier',
        'cost_center',
        'exercise',
        'closing_snapshot',
    ];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $annotation): void {
            $kind = $annotation->getAttribute('kind');
            if (! $kind instanceof HistoricalErrorKind) {
                try {
                    $kind = HistoricalErrorKind::from((string) $kind);
                } catch (\ValueError) {
                    throw ValidationException::withMessages([
                        'kind' => 'Il tipo di errore storico non è previsto dal vocabolario canonico.',
                    ]);
                }
            }
            $annotation->setAttribute('kind', $kind);

            $company = Company::query()->find($annotation->company_id);
            $exercise = Exercise::query()->find($annotation->exercise_id);
            $snapshot = ClosingSnapshot::query()->find($annotation->closing_snapshot_id);
            if ($company === null
                || $exercise === null
                || $snapshot === null
                || $exercise->company_id !== $company->id
                || $snapshot->company_id !== $company->id
                || $snapshot->exercise_id !== $exercise->id
                || $exercise->isOpen()) {
                throw ValidationException::withMessages([
                    'historical_error_annotation' => 'L’Annotazione deve riferire lo stesso Esercizio Chiuso, Azienda e Snapshot di Chiusura.',
                ]);
            }

            $reason = $annotation->getAttribute('reason');
            $recordedFacts = $annotation->getAttribute('recorded_facts');
            $believedCorrectFacts = $annotation->getAttribute('believed_correct_facts');
            $affectedSources = $annotation->getAttribute('affected_sources');
            if (! is_string($reason) || blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio.']);
            }
            foreach ([
                'recorded_facts' => $recordedFacts,
                'believed_correct_facts' => $believedCorrectFacts,
                'affected_sources' => $affectedSources,
            ] as $field => $value) {
                if (! is_array($value) || $value === []) {
                    throw ValidationException::withMessages([
                        $field => 'Il contenuto materializzato è obbligatorio e non può essere vuoto.',
                    ]);
                }
            }
            if (is_array($affectedSources)) {
                foreach ($affectedSources as $index => $source) {
                    if (! is_array($source)
                        || ! isset($source['type'], $source['id'], $source['origin_key'], $source['label'])
                        || ! is_string($source['type'])
                        || ! in_array($source['type'], self::SUPPORTED_SOURCE_TYPES, true)
                        || ! is_numeric($source['id'])
                        || (int) $source['id'] < 1
                        || ! is_string($source['origin_key'])
                        || blank($source['origin_key'])
                        || ! is_string($source['label'])
                        || blank($source['label'])) {
                        throw ValidationException::withMessages([
                            "affected_sources.$index" => 'La sorgente interessata non è un riferimento materializzato valido.',
                        ]);
                    }
                }
            }

            foreach (['recorded_facts_version', 'believed_correct_facts_version', 'affected_sources_version'] as $field) {
                if ((int) $annotation->getAttribute($field) !== 1) {
                    throw ValidationException::withMessages([
                        $field => 'La versione iniziale dell’Annotazione deve essere 1.',
                    ]);
                }
            }
        });

        static::updating(fn (): never => throw new \LogicException('Historical error annotations are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Historical error annotations cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return BelongsTo<ClosingSnapshot, $this> */
    public function closingSnapshot(): BelongsTo
    {
        return $this->belongsTo(ClosingSnapshot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'historical_error_annotation_id')
            ->attached()
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => HistoricalErrorKind::class,
            'recorded_facts_version' => 'integer',
            'recorded_facts' => 'array',
            'believed_correct_facts_version' => 'integer',
            'believed_correct_facts' => 'array',
            'affected_sources_version' => 'integer',
            'affected_sources' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
