<?php

namespace App\Domain\Contracts;

final class ContractImpactFingerprint
{
    /** @param array<string|int, mixed> $payload */
    public static function make(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
