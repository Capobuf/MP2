<?php

namespace App\Domain\Reporting;

final readonly class ReportSource
{
    /**
     * @param  array<string, mixed>  $detail
     * @param  array<int, array<string, mixed>>  $corrections
     * @param  array<int, array<string, mixed>>  $annotations
     * @param  list<array{cost_center_id: int, cost_center_label: string}>  $costCenterLineage
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
        public string $receivedCarryover = '0.00',
        public string $residual = '0.00',
        public string $saving = '0.00',
        public string $unused = '0.00',
        public array $costCenterLineage = [],
        public array $detail = [],
        public array $corrections = [],
        public array $annotations = [],
    ) {}

    public function comparisonValue(): string
    {
        return $this->actual !== '0.00' || $this->hasActuals ? $this->actual : $this->allocation;
    }

    /** @return list<array<string, mixed>> */
    public function expenseComponents(): array
    {
        $budgetExpenses = $this->detail[$this->sourceType]['expenses'] ?? null;
        $expenses = $budgetExpenses ?? ($this->detail['expenses'] ?? []);

        return array_values(array_map(function (array $expense) use ($budgetExpenses): array {
            if ($budgetExpenses !== null) {
                return [
                    'id' => $expense['expense_id'],
                    'source' => $expense['description'],
                    'supplier_id' => $expense['supplier']['id'] ?? null,
                    'supplier_label' => $expense['supplier']['label'] ?? null,
                    'allocation' => $expense['approved_estimate_total'],
                    'actual' => '0.00',
                ];
            }
            if (array_key_exists('final_estimate_total', $expense)) {
                return [
                    'id' => $expense['expense_id'],
                    'source' => $expense['description'],
                    'supplier_id' => $expense['supplier']['id'] ?? null,
                    'supplier_label' => $expense['supplier']['label'] ?? null,
                    'allocation' => $expense['final_estimate_total'],
                    'actual' => $expense['closing_actual_total'],
                ];
            }

            return $expense;
        }, $expenses));
    }
}
