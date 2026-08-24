<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportDefinition
{
    private const FILTERS = ['cost_center_id', 'project_id', 'contract_id', 'expense_id', 'supplier_id'];

    /** @param array<string, int|string|null> $filters */
    public function __construct(
        public int $companyId,
        public ReportKind $kind,
        public int $exerciseId,
        public ?ReportReference $initialReference,
        public ?ReportReference $finalReference,
        public ?ActualReference $actualReference,
        public ?int $comparisonExerciseId,
        public ?CarbonImmutable $dateFrom,
        public ?CarbonImmutable $dateTo,
        public array $filters,
        public CarbonImmutable $generatedAt,
    ) {
        $this->validate();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?CarbonImmutable $generatedAt = null): self
    {
        $kind = ReportKind::tryFrom((string) ($data['kind'] ?? ''))
            ?? throw new InvalidArgumentException('Famiglia report non supportata.');
        $actual = isset($data['actual_reference'])
            ? ActualReference::tryFrom((string) $data['actual_reference'])
            : null;
        if (isset($data['actual_reference']) && $actual === null) {
            throw new InvalidArgumentException('Tipo di Effettivo non supportato.');
        }
        $filters = is_array($data['filters'] ?? null)
            ? array_map(
                fn (mixed $value): mixed => is_string($value) && ctype_digit($value) ? (int) $value : $value,
                $data['filters'],
            )
            : [];

        return new self(
            (int) ($data['company_id'] ?? 0),
            $kind,
            (int) ($data['exercise_id'] ?? 0),
            isset($data['initial_reference']) && is_array($data['initial_reference']) ? ReportReference::fromArray($data['initial_reference']) : null,
            isset($data['final_reference']) && is_array($data['final_reference']) ? ReportReference::fromArray($data['final_reference']) : null,
            $actual,
            isset($data['comparison_exercise_id']) ? (int) $data['comparison_exercise_id'] : null,
            isset($data['date_from']) ? CarbonImmutable::parse((string) $data['date_from']) : null,
            isset($data['date_to']) ? CarbonImmutable::parse((string) $data['date_to']) : null,
            $filters,
            $generatedAt ?? CarbonImmutable::now(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'kind' => $this->kind->value,
            'exercise_id' => $this->exerciseId,
            'initial_reference' => $this->initialReference?->toArray(),
            'final_reference' => $this->finalReference?->toArray(),
            'actual_reference' => $this->actualReference?->value,
            'comparison_exercise_id' => $this->comparisonExerciseId,
            'date_from' => $this->dateFrom?->toDateString(),
            'date_to' => $this->dateTo?->toDateString(),
            'filters' => $this->filters,
        ];
    }

    private function validate(): void
    {
        if ($this->companyId < 1 || $this->exerciseId < 1) {
            throw new InvalidArgumentException('Azienda ed Esercizio sono obbligatori.');
        }
        if (($this->dateFrom === null) !== ($this->dateTo === null)) {
            throw new InvalidArgumentException('L’intervallo richiede entrambe le date.');
        }
        if ($this->dateFrom?->isAfter($this->dateTo) === true) {
            throw new InvalidArgumentException('La data iniziale non può seguire la data finale.');
        }
        if (array_diff(array_keys($this->filters), self::FILTERS) !== []) {
            throw new InvalidArgumentException('Il report contiene filtri non supportati.');
        }
        foreach ($this->filters as $value) {
            if ($value !== null && (! is_int($value) || $value < 1)) {
                throw new InvalidArgumentException('Ogni filtro deve contenere un identificativo valido.');
            }
        }

        $this->validateReferences();
    }

    private function validateReferences(): void
    {
        if ($this->kind->isComparison() && ($this->initialReference === null || $this->finalReference === null)) {
            throw new InvalidArgumentException('Il confronto richiede entrambi i riferimenti.');
        }
        foreach ([$this->initialReference, $this->finalReference] as $reference) {
            if ($reference !== null && ! in_array($reference->exerciseId, array_filter([$this->exerciseId, $this->comparisonExerciseId]), true)) {
                throw new InvalidArgumentException('Il riferimento appartiene a un Esercizio diverso da quelli dichiarati.');
            }
        }

        if ($this->kind === ReportKind::AnnualExecutive) {
            if ($this->actualReference === null || $this->finalReference === null) {
                throw new InvalidArgumentException('La vista annuale richiede un tipo di Effettivo esplicito.');
            }
            if ($this->finalReference->type !== ReferenceType::from($this->actualReference->value)) {
                throw new InvalidArgumentException('Il riferimento finale non coincide con il tipo di Effettivo dichiarato.');
            }
            if ($this->initialReference !== null && ! in_array($this->initialReference->type, [ReferenceType::Budget, ReferenceType::Closing], true)) {
                throw new InvalidArgumentException('Il riferimento iniziale della vista annuale deve essere un Budget o la Chiusura.');
            }
        }

        if ($this->kind === ReportKind::BudgetActual) {
            if ($this->initialReference?->type !== ReferenceType::Budget || $this->actualReference === null) {
                throw new InvalidArgumentException('Budget vs Actual richiede un Budget e un tipo di Effettivo espliciti.');
            }
            $expected = ReferenceType::from($this->actualReference->value);
            if ($this->finalReference?->type !== $expected) {
                throw new InvalidArgumentException('Il riferimento finale non coincide con il tipo di Effettivo dichiarato.');
            }
        }
        if ($this->kind === ReportKind::BudgetCurrentAllocation
            && ($this->initialReference?->type !== ReferenceType::Budget || $this->finalReference?->type !== ReferenceType::Current)) {
            throw new InvalidArgumentException('Budget vs Allocato Corrente richiede Budget e Situazione Corrente.');
        }
        if ($this->kind === ReportKind::BudgetVersions
            && ($this->initialReference?->type !== ReferenceType::Budget || $this->finalReference?->type !== ReferenceType::Budget)) {
            throw new InvalidArgumentException('Il confronto versioni richiede due Budget.');
        }
        if ($this->kind === ReportKind::Exercises) {
            if ($this->comparisonExerciseId === null || $this->comparisonExerciseId === $this->exerciseId) {
                throw new InvalidArgumentException('Il confronto fra Esercizi richiede due Esercizi distinti.');
            }
            if ($this->initialReference?->type !== $this->finalReference?->type) {
                throw new InvalidArgumentException('Il confronto fra Esercizi richiede la stessa misura.');
            }
        } elseif ($this->comparisonExerciseId !== null) {
            throw new InvalidArgumentException('Il secondo Esercizio è ammesso solo nel confronto fra Esercizi.');
        }
    }
}
