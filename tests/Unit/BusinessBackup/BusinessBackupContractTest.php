<?php

use App\BusinessBackup\V1\BusinessBackupContract;
use App\BusinessBackup\V1\PortablePayload;
use App\BusinessBackup\V1\PortableReferences;

it('defines stable unique sheet names within the XLSX limit', function (): void {
    $names = [BusinessBackupContract::MANIFEST, ...BusinessBackupContract::machineSheets(), ...BusinessBackupContract::VISIBLE_SHEETS];

    expect($names)->toHaveCount(count(array_unique($names)));
    foreach ($names as $name) {
        expect(mb_strlen($name))->toBeLessThanOrEqual(31);
    }
});

it('assigns deterministic refs without exposing source ids', function (): void {
    $refs = new PortableReferences;
    $refs->register('supplier', [90, 3, 44]);

    expect($refs->get('supplier', 3))->toBe('SUP-0000000001')
        ->and($refs->get('supplier', 44))->toBe('SUP-0000000002')
        ->and($refs->get('supplier', 90))->toBe('SUP-0000000003');
});

it('preserves exact decimals canonical JSON and long UTF-8 text', function (): void {
    $long = str_repeat('È', 16000).'=formula';
    $sheet = '_MP2_suppliers';
    $prepared = PortablePayload::prepare([
        $sheet => ['columns' => BusinessBackupContract::SCHEMAS[$sheet], 'rows' => [['SUP-0000000001', '=ACME', '', $long, '']]],
        BusinessBackupContract::LONG_PAYLOADS => ['columns' => BusinessBackupContract::SCHEMAS[BusinessBackupContract::LONG_PAYLOADS], 'rows' => []],
    ]);

    expect(PortablePayload::decimal('1.2', 2))->toBe('1.20')
        ->and(PortablePayload::json(['b' => 1, 'a' => 'è']))->toBe('{"a":"è","b":1}')
        ->and($prepared['stored'][$sheet]['rows'][0][1])->toBe('=ACME')
        ->and($prepared['stored'][$sheet]['rows'][0][3])->toStartWith('@payload:')
        ->and(implode('', array_column($prepared['stored'][BusinessBackupContract::LONG_PAYLOADS]['rows'], 7)))->toBe($long);
});
