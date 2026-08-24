<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportReference
{
    public function __construct(
        public ReferenceType $type,
        public int $exerciseId,
        public ?int $budgetSnapshotId = null,
        public ?CarbonImmutable $referenceDate = null,
    ) {
        if ($exerciseId < 1) {
            throw new InvalidArgumentException('Il riferimento richiede un Esercizio valido.');
        }

        if (($type === ReferenceType::Budget) !== ($budgetSnapshotId !== null)) {
            throw new InvalidArgumentException('Solo un riferimento Budget deve indicare una versione Budget.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = ReferenceType::tryFrom((string) ($data['type'] ?? ''))
            ?? throw new InvalidArgumentException('Tipo di riferimento non supportato.');

        return new self(
            $type,
            (int) ($data['exercise_id'] ?? 0),
            isset($data['budget_snapshot_id']) ? (int) $data['budget_snapshot_id'] : null,
            isset($data['reference_date']) ? CarbonImmutable::parse((string) $data['reference_date']) : null,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'exercise_id' => $this->exerciseId,
            'budget_snapshot_id' => $this->budgetSnapshotId,
            'reference_date' => $this->referenceDate?->toDateString(),
        ];
    }
}
