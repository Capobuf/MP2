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

it('classifies every tenant-owned table as portable data or a deliberate exclusion', function (): void {
    $included = [
        'companies', 'tenant_companies', 'suppliers', 'supplier_contacts', 'cost_centers', 'exercises',
        'projects', 'project_transitions', 'project_exercise_classifications', 'contracts',
        'contract_renewal_configurations', 'contract_lifecycle_facts', 'contract_conditions',
        'contract_exercise_classifications', 'project_contract_links', 'expenses', 'expense_lines',
        'project_deferrals', 'budget_snapshots', 'budget_source_rows', 'budget_evidence',
        'closing_snapshots', 'closing_source_rows', 'late_corrections', 'historical_error_annotations',
        'attachments', 'proposals', 'proposal_items', 'proposal_actions',
    ];
    $excluded = ['users', 'company_capabilities', 'audit_events', 'business_backup_imports', 'platform_lifecycle_events', 'pending_file_deletions'];
    $tables = Schema::getTableListing(schemaQualified: false);
    $owned = ['companies', 'tenant_companies'];
    do {
        $previous = $owned;
        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'company_id') || collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => in_array($key['foreign_table'], array_diff($owned, $excluded), true))) {
                $owned[] = $table;
            }
        }
        $owned = array_values(array_unique($owned));
    } while ($owned !== $previous);

    expect(array_diff($owned, $included, $excluded))->toBe([]);
});
