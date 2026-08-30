<?php

namespace App\BusinessBackup\V1;

use InvalidArgumentException;

final class PortablePayload
{
    public const CHUNK_BYTES = 30000;

    public static function json(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string> $columns
     * @param  list<list<string>>  $rows
     */
    public static function checksum(array $columns, array $rows): string
    {
        return hash('sha256', self::json(['columns' => $columns, 'rows' => $rows]));
    }

    public static function decimal(mixed $value, int $scale): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $value = (string) $value;
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal [$value].");
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Decimal [$value] exceeds scale [$scale].");
        }

        return $integer.'.'.str_pad($fraction, $scale, '0');
    }

    /**
     * @param  array<string, array{columns: list<string>, rows: list<list<string>>}>  $sheets
     * @return array{stored: array<string, array{columns: list<string>, rows: list<list<string>>}>, checksums: array<string, string>}
     */
    public static function prepare(array $sheets): array
    {
        $stored = $sheets;
        $checksums = [];
        $payloadRows = [];
        $payloadNumber = 0;

        foreach ($sheets as $sheet => $data) {
            if ($sheet === BusinessBackupContract::LONG_PAYLOADS) {
                continue;
            }
            $checksums[$sheet] = self::checksum($data['columns'], $data['rows']);
            foreach ($data['rows'] as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    if (strlen($value) <= self::CHUNK_BYTES && ! str_starts_with($value, '@payload:')) {
                        continue;
                    }
                    $payloadRef = sprintf('PAY-%010d', ++$payloadNumber);
                    $chunks = self::chunks($value);
                    foreach ($chunks as $chunkIndex => $chunk) {
                        $payloadRows[] = [
                            $payloadRef,
                            $sheet,
                            $row[0],
                            $data['columns'][$columnIndex],
                            (string) ($chunkIndex + 1),
                            (string) count($chunks),
                            hash('sha256', $chunk),
                            $chunk,
                        ];
                    }
                    $stored[$sheet]['rows'][$rowIndex][$columnIndex] = '@payload:'.$payloadRef;
                }
            }
        }

        $stored[BusinessBackupContract::LONG_PAYLOADS] = [
            'columns' => BusinessBackupContract::SCHEMAS[BusinessBackupContract::LONG_PAYLOADS],
            'rows' => $payloadRows,
        ];
        $checksums[BusinessBackupContract::LONG_PAYLOADS] = self::checksum(
            $stored[BusinessBackupContract::LONG_PAYLOADS]['columns'],
            $payloadRows,
        );

        return ['stored' => $stored, 'checksums' => $checksums];
    }

    /** @return list<string> */
    private static function chunks(string $value): array
    {
        $chunks = [];
        $offset = 0;
        while ($offset < strlen($value)) {
            $chunk = mb_strcut($value, $offset, self::CHUNK_BYTES, 'UTF-8');
            if ($chunk === '') {
                throw new InvalidArgumentException('Long payload cannot be split as valid UTF-8.');
            }
            $chunks[] = $chunk;
            $offset += strlen($chunk);
        }

        return $chunks === [] ? [''] : $chunks;
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sort(...), $value);
    }
}
