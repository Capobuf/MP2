<?php

namespace App\BusinessBackup;

final readonly class BackupPreview
{
    /** @param array<string, int> $counts
     * @param  list<array{year: int, status: string}>  $exercises
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $packageId,
        public int $formatVersion,
        public string $companyName,
        public string $companyTimezone,
        public string $exportedAt,
        public array $counts,
        public array $exercises,
        public string $budgetTotal,
        public string $closingActualTotal,
        public int $attachmentCount,
        public array $warnings,
    ) {}
}
