<?php

namespace App\Domain\Reporting;

use App\Domain\Expenses\Decimal;

final class ReportAggregator
{
    /**
     * @param  array<int, ReportSource>  $sources
     * @return array<string, string|int>
     */
    public function executive(array $sources): array
    {
        return [
            'source_count' => count($sources),
            'allocation' => Decimal::sum(array_map(fn (ReportSource $source): string => $source->allocation, $sources)),
            'actual' => Decimal::sum(array_map(fn (ReportSource $source): string => $source->actual, $sources)),
            'operational_variance' => Decimal::subtract(
                Decimal::sum(array_map(fn (ReportSource $source): string => $source->actual, $sources)),
                Decimal::sum(array_map(fn (ReportSource $source): string => $source->allocation, $sources)),
            ),
            'carryover' => Decimal::sum(array_map(fn (ReportSource $source): string => $source->carryover, $sources)),
            'unclassified' => Decimal::sum(array_map(
                fn (ReportSource $source): string => $source->costCenterId === null ? $source->actual : '0.00',
                $sources,
            )),
        ];
    }

    /**
     * @param  array<int, ReportSource>  $sources
     * @return array<int, array<string, mixed>>
     */
    public function suppliers(array $sources): array
    {
        $buckets = [];
        foreach ($sources as $source) {
            $expenses = $source->sourceType === 'expense'
                ? [[
                    'supplier_id' => $source->supplierId,
                    'supplier_label' => $source->supplierLabel,
                    'allocation' => $source->allocation,
                    'actual' => $source->actual,
                    'source' => $source->label,
                ]]
                : ($source->detail['expenses'] ?? []);

            foreach ($expenses as $expense) {
                $supplierId = $source->sourceType === 'contract'
                    ? $source->supplierId
                    : ($expense['supplier_id'] ?? null);
                $supplierLabel = $source->sourceType === 'contract'
                    ? $source->supplierLabel
                    : ($expense['supplier_label'] ?? null);
                $key = $supplierId === null ? 'without_supplier' : 'supplier:'.$supplierId;
                $buckets[$key] ??= [
                    'key' => $key,
                    'label' => $supplierLabel ?? 'Senza Fornitore',
                    'allocation' => '0.00',
                    'actual' => '0.00',
                    'sources' => [],
                ];
                $buckets[$key]['allocation'] = Decimal::add($buckets[$key]['allocation'], (string) ($expense['allocation'] ?? '0.00'));
                $buckets[$key]['actual'] = Decimal::add($buckets[$key]['actual'], (string) ($expense['actual'] ?? '0.00'));
                $buckets[$key]['sources'][] = $expense['source'] ?? $source->label;
            }

            if (Decimal::compare($source->carryover, '0.00') !== 0) {
                $key = 'carryover_without_supplier';
                $buckets[$key] ??= [
                    'key' => $key,
                    'label' => 'Riporto senza Fornitore',
                    'allocation' => '0.00',
                    'actual' => '0.00',
                    'sources' => [],
                ];
                $buckets[$key]['allocation'] = Decimal::add($buckets[$key]['allocation'], $source->carryover);
                $buckets[$key]['sources'][] = $source->label;
            }
        }

        foreach ($buckets as &$bucket) {
            $bucket['operational_variance'] = Decimal::subtract($bucket['actual'], $bucket['allocation']);
        }

        return array_values($buckets);
    }
}
