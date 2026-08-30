<?php

namespace App\BusinessBackup\V1;

use InvalidArgumentException;

final class PortableReferences
{
    /** @var array<string, array<int, string>> */
    private array $references = [];

    /** @param list<int> $ids */
    public function register(string $type, array $ids): void
    {
        $prefix = BusinessBackupContract::PREFIXES[$type] ?? throw new InvalidArgumentException("Unknown portable reference type [$type].");
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        foreach ($ids as $index => $id) {
            $this->references[$type][$id] = sprintf('%s-%010d', $prefix, $index + 1);
        }
    }

    public function get(string $type, int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return '';
        }

        return $this->references[$type][(int) $id]
            ?? throw new InvalidArgumentException("Missing portable reference for [$type:$id].");
    }

    public function origin(string $type, ?string $originKey): string
    {
        if ($originKey === null || $originKey === '') {
            return '';
        }
        if (! preg_match('/^(expense|project|contract):(\d+)$/', $originKey, $matches)) {
            throw new InvalidArgumentException("Invalid origin key [$originKey].");
        }

        return $this->get($matches[1], (int) $matches[2]);
    }
}
