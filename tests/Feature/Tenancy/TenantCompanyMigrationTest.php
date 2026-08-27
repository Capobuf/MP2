<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('backfills exactly one active Tenant with the same identifier for every existing Company', function (): void {
    $migration = require database_path('migrations/2026_08_26_000100_create_tenant_companies_table.php');
    $migration->down();

    $companyIds = collect(['Azienda 30', 'Azienda 10', 'Azienda 20'])
        ->map(fn (string $name): int => DB::table('companies')->insertGetId([
            'name' => $name,
            'timezone' => 'Europe/Rome',
            'overspend_note_required' => false,
            'unclassified_closing_policy' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
        ->sort()
        ->values();

    $migration->up();

    expect(DB::table('tenant_companies')->orderBy('company_id')->pluck('company_id'))
        ->toEqual($companyIds)
        ->and(DB::table('tenant_companies')->where('status', 'active')->count())
        ->toBe($companyIds->count())
        ->and(DB::table('companies as c')
            ->leftJoin('tenant_companies as tc', 'tc.company_id', '=', 'c.id')
            ->whereNull('tc.company_id')
            ->count())->toBe(0);

    expect(fn () => DB::table('tenant_companies')->insert([
        'company_id' => $companyIds->first(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $invalidStatusCompanyId = DB::table('companies')->insertGetId([
        'name' => 'Stato non valido',
        'timezone' => 'Europe/Rome',
        'overspend_note_required' => false,
        'unclassified_closing_policy' => 'warning',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('tenant_companies')->insert([
        'company_id' => $invalidStatusCompanyId,
        'status' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
