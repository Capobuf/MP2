<?php

namespace App\Actions\Tenancy;

final readonly class TenantDestructionResult
{
    public function __construct(
        public string $operationId,
        public int $filesProcessed,
        public int $filesCompleted,
        public int $filesPending,
    ) {}

    public function isComplete(): bool
    {
        return $this->filesPending === 0;
    }
}
