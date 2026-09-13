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

            if (Decimal::compare($source->receivedCarryover, '0.00') !== 0) {
                $key = 'carryover_without_supplier';
                $buckets[$key] ??= [
                    'key' => $key,
                    'label' => 'Riporto senza Fornitore',
                    'allocation' => '0.00',
                    'actual' => '0.00',
                    'sources' => [],
                ];
                $buckets[$key]['allocation'] = Decimal::add($buckets[$key]['allocation'], $source->receivedCarryover);
                $buckets[$key]['sources'][] = $source->label;
            }
        }

        foreach ($buckets as &$bucket) {
            $bucket['operational_variance'] = Decimal::subtract($bucket['actual'], $bucket['allocation']);
        }

        return array_values($buckets);
    }

    /**
     * Builds direct and branch totals without adding branch roll-ups to company totals.
     *
     * @param  array<int, ReportSource>  $sources
     * @return array<int, array<string, mixed>>
     */
    public function costCenters(array $sources): array
    {
        $buckets = [];

        foreach ($sources as $source) {
            if ($source->costCenterId === null) {
                $buckets['unclassified'] ??= $this->emptyCostCenterBucket('unclassified', null, 'Non classificato');
                $this->addDirectAndBranch($buckets['unclassified'], $source);

                continue;
            }

            $lineage = $source->costCenterLineage !== []
                ? $source->costCenterLineage
                : [['cost_center_id' => $source->costCenterId, 'cost_center_label' => $source->costCenterLabel ?? (string) $source->costCenterId]];
            $path = [];
            foreach ($lineage as $index => $node) {
                $id = $node['cost_center_id'];
                if ($id < 1) {
                    continue;
                }
                $path[] = $node['cost_center_label'];
                $key = 'cost-center:'.$id;
                $buckets[$key] ??= $this->emptyCostCenterBucket($key, $id, implode(' / ', $path));
                $this->addBranch($buckets[$key], $source);
                if ($index === array_key_last($lineage)) {
                    $this->addDirect($buckets[$key], $source);
                }
            }
        }

        foreach ($buckets as &$bucket) {
            $bucket['direct_operational_variance'] = Decimal::subtract($bucket['direct_actual'], $bucket['direct_allocation']);
            $bucket['branch_operational_variance'] = Decimal::subtract($bucket['branch_actual'], $bucket['branch_allocation']);
            $bucket['allocation'] = $bucket['branch_allocation'];
            $bucket['actual'] = $bucket['branch_actual'];
            $bucket['operational_variance'] = $bucket['branch_operational_variance'];
        }
        unset($bucket);

        uasort($buckets, fn (array $left, array $right): int => strnatcasecmp((string) $left['label'], (string) $right['label']));

        return array_values($buckets);
    }

    /** @return array<string, mixed> */
    private function emptyCostCenterBucket(string $key, ?int $id, string $label): array
    {
        return [
            'key' => $key,
            'cost_center_id' => $id,
            'label' => $label,
            'direct_allocation' => '0.00',
            'direct_actual' => '0.00',
            'direct_carryover' => '0.00',
            'direct_count' => 0,
            'branch_allocation' => '0.00',
            'branch_actual' => '0.00',
            'branch_carryover' => '0.00',
            'branch_count' => 0,
        ];
    }

    /** @param array<string, mixed> $bucket */
    private function addDirectAndBranch(array &$bucket, ReportSource $source): void
    {
        $this->addDirect($bucket, $source);
        $this->addBranch($bucket, $source);
    }

    /** @param array<string, mixed> $bucket */
    private function addDirect(array &$bucket, ReportSource $source): void
    {
        $bucket['direct_allocation'] = Decimal::add($bucket['direct_allocation'], $source->allocation);
        $bucket['direct_actual'] = Decimal::add($bucket['direct_actual'], $source->actual);
        $bucket['direct_carryover'] = Decimal::add($bucket['direct_carryover'], $source->carryover);
        $bucket['direct_count']++;
    }

    /** @param array<string, mixed> $bucket */
    private function addBranch(array &$bucket, ReportSource $source): void
    {
        $bucket['branch_allocation'] = Decimal::add($bucket['branch_allocation'], $source->allocation);
        $bucket['branch_actual'] = Decimal::add($bucket['branch_actual'], $source->actual);
        $bucket['branch_carryover'] = Decimal::add($bucket['branch_carryover'], $source->carryover);
        $bucket['branch_count']++;
    }
}
