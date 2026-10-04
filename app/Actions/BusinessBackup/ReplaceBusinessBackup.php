<?php

namespace App\Actions\BusinessBackup;

use App\Models\Company;
use App\Models\User;

final class ReplaceBusinessBackup
{
    /** @param array<string, mixed> $package */
    public function execute(User $actor, array $package, string $importOperationId, int $expectedTargetCompanyId, bool $irreversibilityConfirmed, bool $destructionConfirmed): Company
    {
        return app(ImportBusinessBackup::class)->execute($actor, $package, $importOperationId, 'replace', $expectedTargetCompanyId, $irreversibilityConfirmed, $destructionConfirmed);
    }
}
