<?php

namespace App\Support\Reporting;

use App\Actions\Reporting\BuildReport;
use App\Domain\Company\Capability;
use App\Domain\Expenses\Decimal;
use App\Domain\Reporting\ActualReference;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ReferenceType;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportReference;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class EconomicDashboardReadModel
{
    /** @var array<string, array<string, mixed>> */
    private array $resolved = [];

    public function __construct(private readonly BuildReport $buildReport) {}

    /** @return array<string, mixed> */
    public function load(User $user, Company $company, Exercise $exercise, ?BudgetSnapshot $budget): array
    {
        if (! $user->hasCapability($company, Capability::View)) {
            throw new AuthorizationException('Non autorizzato a visualizzare la Dashboard di questa Azienda.');
        }

        if ((int) $exercise->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'exercise_id' => 'L’Esercizio non appartiene all’Azienda corrente.',
            ]);
        }

        if ($budget instanceof BudgetSnapshot
            && ((int) $budget->company_id !== (int) $company->id || (int) $budget->exercise_id !== (int) $exercise->id)) {
            throw ValidationException::withMessages([
                'budget_id' => 'Il Budget non appartiene all’Azienda e all’Esercizio correnti.',
            ]);
        }

        $cacheKey = implode(':', [
            $user->id,
            $company->id,
            $exercise->id,
            $budget instanceof BudgetSnapshot ? $budget->id : 'none',
        ]);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $definition = new ReportDefinition(
            companyId: $company->id,
            kind: ReportKind::AnnualExecutive,
            exerciseId: $exercise->id,
            initialReference: $budget instanceof BudgetSnapshot
                ? new ReportReference(ReferenceType::Budget, $exercise->id, $budget->id)
                : null,
            finalReference: new ReportReference(ReferenceType::Current, $exercise->id),
            actualReference: ActualReference::Current,
            comparisonExerciseId: null,
            dateFrom: null,
            dateTo: null,
            filters: [],
            generatedAt: CarbonImmutable::now($company->timezone),
        );
        $report = $this->buildReport->execute($user, $definition);

        return $this->resolved[$cacheKey] = $this->transform($company, $exercise, $budget, $report);
    }

    /** @return array<string, mixed> */
    private function transform(Company $company, Exercise $exercise, ?BudgetSnapshot $budget, ReportResult $report): array
    {
        [$budgetSources, $currentSources] = $this->referenceSources($budget, $report);
        $sources = $this->sourceRows($company, $budget, $report, $currentSources);
        $costCenters = $this->costCenterRows($company, $exercise, $budget, $budgetSources, $currentSources);
        $categoryCounts = collect(ComparisonCategory::cases())
            ->mapWithKeys(fn (ComparisonCategory $category): array => [
                $category->value => (int) ($report->categoryCounts[$category->value] ?? 0),
            ])->all();

        return [
            'company_id' => $company->id,
            'exercise_id' => $exercise->id,
            'exercise_year' => $exercise->year,
            'has_budget' => $budget instanceof BudgetSnapshot,
            'budget_id' => $budget?->id,
            'budget_version' => $budget?->version,
            'budget_label' => $budget instanceof BudgetSnapshot
                ? 'Budget v'.$budget->version.' · '.$budget->purpose->label()
                : null,
            'summary' => [
                'budget' => $budget instanceof BudgetSnapshot ? (string) $budget->total_approved_allocation : null,
                'allocation' => (string) $report->totals['current_allocation'],
                'actual' => (string) $report->totals['current_actual'],
                'operational_variance' => (string) $report->totals['current_operational_variance'],
            ],
            'sources' => $sources,
            'cost_centers' => $costCenters,
            'comparison_categories' => $categoryCounts,
            'comparison_source_count' => array_sum($categoryCounts),
            'comparison_url' => $budget instanceof BudgetSnapshot
                ? Reports::getUrl([
                    'exerciseId' => $exercise->id,
                    'kind' => ReportKind::BudgetCurrentAllocation->value,
                    'budgetId' => $budget->id,
                    'auto' => 1,
                ], tenant: $company)
                : null,
        ];
    }

    /**
     * @return array{array<int, ReportSource>, array<int, ReportSource>}
     */
    private function referenceSources(?BudgetSnapshot $budget, ReportResult $report): array
    {
        if (! $budget instanceof BudgetSnapshot) {
            return [[], $report->sources];
        }

        $budgetSources = [];
        $currentSources = [];
        foreach ($report->comparisons as $comparison) {
            $before = $comparison['initial_source'];
            $after = $comparison['final_source'];
            if ($before instanceof ReportSource) {
                $budgetSources[] = $before;
            }
            if ($after instanceof ReportSource) {
                $currentSources[] = $after;
            }
        }

        return [$budgetSources, $currentSources];
    }

    /**
     * @param  array<int, ReportSource>  $currentSources
     * @return array<int, array<string, mixed>>
     */
    private function sourceRows(Company $company, ?BudgetSnapshot $budget, ReportResult $report, array $currentSources): array
    {
        if ($budget instanceof BudgetSnapshot) {
            $rows = array_map(function (array $comparison) use ($company): array {
                /** @var ReportSource|null $before */
                $before = $comparison['initial_source'];
                /** @var ReportSource|null $after */
                $after = $comparison['final_source'];
                $source = $after ?? $before;
                if (! $source instanceof ReportSource) {
                    throw new \LogicException('Il confronto contiene una sorgente priva di riferimenti.');
                }

                $allocation = $after instanceof ReportSource ? $after->allocation : '0.00';
                $actual = $after instanceof ReportSource ? $after->actual : '0.00';
                $budgetAllocation = $before instanceof ReportSource ? $before->allocation : '0.00';

                return [
                    'origin_key' => $source->originKey,
                    'source_type' => $source->sourceType,
                    'origin_id' => $source->originId,
                    'label' => $source->label,
                    'budget' => $budgetAllocation,
                    'allocation' => $allocation,
                    'actual' => $actual,
                    'operational_variance' => Decimal::subtract($actual, $allocation),
                    'allocation_vs_budget' => Decimal::subtract($allocation, $budgetAllocation),
                    'url' => $this->sourceUrl($company, $source),
                ];
            }, $report->comparisons);
        } else {
            $rows = array_map(function (ReportSource $source) use ($company): array {
                return [
                    'origin_key' => $source->originKey,
                    'source_type' => $source->sourceType,
                    'origin_id' => $source->originId,
                    'label' => $source->label,
                    'budget' => null,
                    'allocation' => $source->allocation,
                    'actual' => $source->actual,
                    'operational_variance' => Decimal::subtract($source->actual, $source->allocation),
                    'allocation_vs_budget' => null,
                    'url' => $this->sourceUrl($company, $source),
                ];
            }, $currentSources);
        }

        usort($rows, function (array $left, array $right): int {
            $allocationOrder = Decimal::compare((string) $right['allocation'], (string) $left['allocation']);

            return $allocationOrder !== 0
                ? $allocationOrder
                : strcmp((string) $left['origin_key'], (string) $right['origin_key']);
        });

        return $rows;
    }

    /**
     * @param  array<int, ReportSource>  $budgetSources
     * @param  array<int, ReportSource>  $currentSources
     * @return array<int, array<string, mixed>>
     */
    private function costCenterRows(
        Company $company,
        Exercise $exercise,
        ?BudgetSnapshot $budget,
        array $budgetSources,
        array $currentSources,
    ): array {
        $buckets = [];

        foreach ($budgetSources as $source) {
            $key = $source->costCenterId === null ? 'unclassified' : 'cost_center:'.$source->costCenterId;
            $buckets[$key] ??= $this->emptyCostCenterBucket($source, $key);
            $buckets[$key]['budget'] = Decimal::add($buckets[$key]['budget'], $source->allocation);
        }

        foreach ($currentSources as $source) {
            $key = $source->costCenterId === null ? 'unclassified' : 'cost_center:'.$source->costCenterId;
            $buckets[$key] ??= $this->emptyCostCenterBucket($source, $key);
            if ($source->costCenterLabel !== null) {
                $buckets[$key]['label'] = $source->costCenterLabel;
            }
            $buckets[$key]['allocation'] = Decimal::add($buckets[$key]['allocation'], $source->allocation);
            $buckets[$key]['actual'] = Decimal::add($buckets[$key]['actual'], $source->actual);
        }

        foreach ($buckets as &$bucket) {
            $bucket['operational_variance'] = Decimal::subtract($bucket['actual'], $bucket['allocation']);
            $bucket['url'] = $bucket['cost_center_id'] === null
                ? null
                : Reports::getUrl(array_filter([
                    'exerciseId' => $exercise->id,
                    'kind' => ReportKind::AnnualExecutive->value,
                    'budgetId' => $budget?->id,
                    'actualReference' => ActualReference::Current->value,
                    'costCenterId' => $bucket['cost_center_id'],
                    'auto' => 1,
                ], fn (mixed $value): bool => $value !== null), tenant: $company);
        }
        unset($bucket);

        uasort($buckets, function (array $left, array $right): int {
            $allocationOrder = Decimal::compare((string) $right['allocation'], (string) $left['allocation']);

            return $allocationOrder !== 0
                ? $allocationOrder
                : strcmp((string) $left['key'], (string) $right['key']);
        });

        return array_values($buckets);
    }

    /** @return array<string, mixed> */
    private function emptyCostCenterBucket(ReportSource $source, string $key): array
    {
        return [
            'key' => $key,
            'cost_center_id' => $source->costCenterId,
            'label' => $source->costCenterLabel ?? 'Non classificato',
            'budget' => '0.00',
            'allocation' => '0.00',
            'actual' => '0.00',
            'operational_variance' => '0.00',
            'url' => null,
        ];
    }

    private function sourceUrl(Company $company, ReportSource $source): string
    {
        return match ($source->sourceType) {
            'expense' => ExpenseResource::getUrl('view', ['record' => $source->originId], tenant: $company),
            'project' => ProjectResource::getUrl('view', ['record' => $source->originId], tenant: $company),
            'contract' => ContractResource::getUrl('view', ['record' => $source->originId], tenant: $company),
            default => throw new \UnexpectedValueException('Tipo di sorgente economica non supportato.'),
        };
    }
}
