<?php

namespace App\Domain\Reporting;

final readonly class ReportSource
{
    /**
     * @param  array<string, mixed>  $detail
     * @param  array<int, array<string, mixed>>  $corrections
     * @param  array<int, array<string, mixed>>  $annotations
     */
    public function __construct(
        public string $sourceType,
        public int $originId,
        public string $originKey,
        public ?string $copiedFromOriginKey,
        public string $label,
        public ?string $summary,
        public ?int $supplierId,
        public ?string $supplierLabel,
        public ?int $costCenterId,
        public ?string $costCenterLabel,
        public ?string $state,
        public string $allocation,
        public string $actual,
        public bool $hasActuals,
        public string $carryover = '0.00',
        public string $residual = '0.00',
        public string $saving = '0.00',
        public string $unused = '0.00',
        public array $detail = [],
        public array $corrections = [],
        public array $annotations = [],
    ) {}

    public function comparisonValue(): string
    {
        return $this->actual !== '0.00' || $this->hasActuals ? $this->actual : $this->allocation;
    }
}
