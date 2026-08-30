<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('allows only the historical actor and Budget proposal columns required by restore to be nullable', function (): void {
    foreach ([
        ['project_transitions', 'created_by_id'],
        ['contract_renewal_configurations', 'created_by_id'],
        ['contract_lifecycle_facts', 'created_by_id'],
        ['contract_conditions', 'created_by_id'],
        ['budget_snapshots', 'proposal_id'],
        ['budget_snapshots', 'approved_by_id'],
        ['closing_snapshots', 'closed_by_id'],
        ['late_corrections', 'recorded_by_id'],
        ['historical_error_annotations', 'recorded_by_id'],
    ] as [$table, $column]) {
        expect(collect(Schema::getColumns($table))->contains(fn (array $definition): bool => $definition['name'] === $column && $definition['nullable']))->toBeTrue();
    }

    expect(Schema::hasTable('business_backup_imports'))->toBeTrue()
        ->and(Schema::hasColumns('business_backup_imports', ['package_id', 'format_version', 'company_id', 'imported_by_id', 'completed_at']))->toBeTrue();
});
