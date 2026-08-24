<?php

namespace App\Domain\Reporting;

final readonly class ReportResult
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, string|int|float>  $totals
     * @param  array<int, ReportSource>  $sources
     * @param  array<int, array<string, mixed>>  $comparisons
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, int>  $labelCounts
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function __construct(
        public ReportDefinition $definition,
        public array $header,
        public array $totals,
        public array $sources,
        public array $comparisons = [],
        public array $categoryCounts = [],
        public array $labelCounts = [],
        public array $sections = [],
    ) {}
}
