<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{table: string, delete: string}> */
    private const FOREIGN_KEYS = [
        'company_capabilities_company_id_foreign' => ['table' => 'company_capabilities', 'delete' => 'CASCADE'],
        'audit_events_company_id_foreign' => ['table' => 'audit_events', 'delete' => 'CASCADE'],
        'suppliers_company_id_foreign' => ['table' => 'suppliers', 'delete' => 'CASCADE'],
        'cost_centers_company_id_foreign' => ['table' => 'cost_centers', 'delete' => 'CASCADE'],
        'exercises_company_id_foreign' => ['table' => 'exercises', 'delete' => 'CASCADE'],
        'expenses_company_id_foreign' => ['table' => 'expenses', 'delete' => 'CASCADE'],
        'projects_company_id_foreign' => ['table' => 'projects', 'delete' => 'CASCADE'],
        'project_transitions_company_id_foreign' => ['table' => 'project_transitions', 'delete' => 'CASCADE'],
        'project_exercise_classifications_company_id_foreign' => ['table' => 'project_exercise_classifications', 'delete' => 'CASCADE'],
        'contracts_company_id_foreign' => ['table' => 'contracts', 'delete' => 'CASCADE'],
        'contract_renewal_configurations_company_id_foreign' => ['table' => 'contract_renewal_configurations', 'delete' => 'CASCADE'],
        'contract_lifecycle_facts_company_id_foreign' => ['table' => 'contract_lifecycle_facts', 'delete' => 'CASCADE'],
        'contract_conditions_company_id_foreign' => ['table' => 'contract_conditions', 'delete' => 'CASCADE'],
        'contract_exercise_classifications_company_id_foreign' => ['table' => 'contract_exercise_classifications', 'delete' => 'CASCADE'],
        'project_contract_links_company_id_foreign' => ['table' => 'project_contract_links', 'delete' => 'CASCADE'],
        'attachments_company_id_foreign' => ['table' => 'attachments', 'delete' => 'CASCADE'],
        'proposals_company_id_foreign' => ['table' => 'proposals', 'delete' => 'CASCADE'],
        'budget_snapshots_company_id_foreign' => ['table' => 'budget_snapshots', 'delete' => 'CASCADE'],
        'project_deferrals_company_id_foreign' => ['table' => 'project_deferrals', 'delete' => 'CASCADE'],
        'closing_snapshots_company_id_foreign' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_source_rows_company_id_foreign' => ['table' => 'closing_source_rows', 'delete' => 'CASCADE'],
        'late_corrections_company_id_foreign' => ['table' => 'late_corrections', 'delete' => 'CASCADE'],
        'historical_error_annotations_company_id_foreign' => ['table' => 'historical_error_annotations', 'delete' => 'CASCADE'],
        'supplier_contacts_supplier_id_foreign' => ['table' => 'supplier_contacts', 'delete' => 'CASCADE'],
        'expenses_exercise_company_foreign' => ['table' => 'expenses', 'delete' => 'CASCADE'],
        'expenses_supplier_company_foreign' => ['table' => 'expenses', 'delete' => 'CASCADE'],
        'expenses_cost_center_company_foreign' => ['table' => 'expenses', 'delete' => 'CASCADE'],
        'expenses_project_company_foreign' => ['table' => 'expenses', 'delete' => 'CASCADE'],
        'expenses_contract_company_foreign' => ['table' => 'expenses', 'delete' => 'CASCADE'],
        'expense_lines_expense_id_foreign' => ['table' => 'expense_lines', 'delete' => 'CASCADE'],
        'project_transitions_project_company_foreign' => ['table' => 'project_transitions', 'delete' => 'CASCADE'],
        'project_classifications_project_company_foreign' => ['table' => 'project_exercise_classifications', 'delete' => 'CASCADE'],
        'project_classifications_exercise_company_foreign' => ['table' => 'project_exercise_classifications', 'delete' => 'CASCADE'],
        'project_classifications_cost_center_company_foreign' => ['table' => 'project_exercise_classifications', 'delete' => 'CASCADE'],
        'contracts_supplier_company_foreign' => ['table' => 'contracts', 'delete' => 'CASCADE'],
        'contract_renewal_configurations_contract_company_foreign' => ['table' => 'contract_renewal_configurations', 'delete' => 'CASCADE'],
        'contract_lifecycle_facts_contract_company_foreign' => ['table' => 'contract_lifecycle_facts', 'delete' => 'CASCADE'],
        'contract_lifecycle_facts_configuration_company_foreign' => ['table' => 'contract_lifecycle_facts', 'delete' => 'CASCADE'],
        'contract_conditions_contract_company_foreign' => ['table' => 'contract_conditions', 'delete' => 'CASCADE'],
        'contract_classifications_contract_company_foreign' => ['table' => 'contract_exercise_classifications', 'delete' => 'CASCADE'],
        'contract_classifications_exercise_company_foreign' => ['table' => 'contract_exercise_classifications', 'delete' => 'CASCADE'],
        'contract_classifications_cost_center_company_foreign' => ['table' => 'contract_exercise_classifications', 'delete' => 'CASCADE'],
        'project_contract_links_project_company_foreign' => ['table' => 'project_contract_links', 'delete' => 'CASCADE'],
        'project_contract_links_contract_company_foreign' => ['table' => 'project_contract_links', 'delete' => 'CASCADE'],
        'attachments_expense_line_id_foreign' => ['table' => 'attachments', 'delete' => 'CASCADE'],
        'attachments_contract_company_foreign' => ['table' => 'attachments', 'delete' => 'CASCADE'],
        'attachments_expense_company_foreign' => ['table' => 'attachments', 'delete' => 'CASCADE'],
        'attachments_proposal_company_foreign' => ['table' => 'attachments', 'delete' => 'CASCADE'],
        'attachments_historical_annotation_company_foreign' => ['table' => 'attachments', 'delete' => 'CASCADE'],
        'proposals_exercise_company_foreign' => ['table' => 'proposals', 'delete' => 'CASCADE'],
        'proposals_reference_budget_id_foreign' => ['table' => 'proposals', 'delete' => 'SET NULL'],
        'proposal_items_proposal_company_foreign' => ['table' => 'proposal_items', 'delete' => 'CASCADE'],
        'proposal_items_expense_company_foreign' => ['table' => 'proposal_items', 'delete' => 'CASCADE'],
        'proposal_items_project_company_foreign' => ['table' => 'proposal_items', 'delete' => 'CASCADE'],
        'proposal_items_contract_company_foreign' => ['table' => 'proposal_items', 'delete' => 'CASCADE'],
        'proposal_actions_proposal_company_foreign' => ['table' => 'proposal_actions', 'delete' => 'CASCADE'],
        'proposal_actions_item_company_foreign' => ['table' => 'proposal_actions', 'delete' => 'CASCADE'],
        'budget_snapshots_exercise_company_foreign' => ['table' => 'budget_snapshots', 'delete' => 'CASCADE'],
        'budget_snapshots_proposal_company_foreign' => ['table' => 'budget_snapshots', 'delete' => 'CASCADE'],
        'budget_snapshots_previous_budget_id_foreign' => ['table' => 'budget_snapshots', 'delete' => 'SET NULL'],
        'budget_rows_snapshot_company_foreign' => ['table' => 'budget_source_rows', 'delete' => 'CASCADE'],
        'budget_evidence_snapshot_company_foreign' => ['table' => 'budget_evidence', 'delete' => 'CASCADE'],
        'budget_evidence_attachment_company_foreign' => ['table' => 'budget_evidence', 'delete' => 'CASCADE'],
        'project_deferrals_project_company_foreign' => ['table' => 'project_deferrals', 'delete' => 'CASCADE'],
        'project_deferrals_source_company_foreign' => ['table' => 'project_deferrals', 'delete' => 'CASCADE'],
        'project_deferrals_destination_company_foreign' => ['table' => 'project_deferrals', 'delete' => 'CASCADE'],
        'closing_snapshots_exercise_company_foreign' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_snapshots_initial_budget_company_foreign' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_snapshots_current_budget_company_foreign' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_snapshots_next_exercise_company_foreign' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_initial_budget_exercise_company_fk' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_current_budget_exercise_company_fk' => ['table' => 'closing_snapshots', 'delete' => 'CASCADE'],
        'closing_rows_snapshot_company_foreign' => ['table' => 'closing_source_rows', 'delete' => 'CASCADE'],
        'late_corrections_exercise_company_foreign' => ['table' => 'late_corrections', 'delete' => 'CASCADE'],
        'late_corrections_snapshot_company_foreign' => ['table' => 'late_corrections', 'delete' => 'CASCADE'],
        'late_corrections_expense_company_foreign' => ['table' => 'late_corrections', 'delete' => 'CASCADE'],
        'late_corrections_expense_line_id_foreign' => ['table' => 'late_corrections', 'delete' => 'CASCADE'],
        'late_corrections_original_expense_line_id_foreign' => ['table' => 'late_corrections', 'delete' => 'CASCADE'],
        'historical_annotations_exercise_company_foreign' => ['table' => 'historical_error_annotations', 'delete' => 'CASCADE'],
        'historical_annotations_snapshot_company_foreign' => ['table' => 'historical_error_annotations', 'delete' => 'CASCADE'],
    ];

    public function up(): void
    {
        $definitions = $this->validateAndReadDefinitions(
            array_fill_keys(array_keys(self::FOREIGN_KEYS), 'RESTRICT'),
        );

        $this->replaceExpenseGeneratedColumns('STORED', 'VIRTUAL');
        $this->replaceProjectContractLinkGeneratedColumn('STORED', 'VIRTUAL');

        foreach ($definitions as $name => $definition) {
            $this->replaceForeignKey($name, $definition, self::FOREIGN_KEYS[$name]['delete']);
        }

        $this->assertDeleteRules(array_map(
            fn (array $foreignKey): string => $foreignKey['delete'],
            self::FOREIGN_KEYS,
        ));
    }

    public function down(): void
    {
        $expectedRules = array_map(
            fn (array $foreignKey): string => $foreignKey['delete'],
            self::FOREIGN_KEYS,
        );
        $definitions = $this->validateAndReadDefinitions($expectedRules);

        foreach ($definitions as $name => $definition) {
            $this->replaceForeignKey($name, $definition, 'RESTRICT');
        }

        $this->replaceProjectContractLinkGeneratedColumn('VIRTUAL', 'STORED');
        $this->replaceExpenseGeneratedColumns('VIRTUAL', 'STORED');
        $this->assertDeleteRules(array_fill_keys(array_keys(self::FOREIGN_KEYS), 'RESTRICT'));
    }

    /**
     * @param  array<string, string>  $expectedRules
     * @return array<string, array{table: string, referenced_table: string, columns: list<string>, referenced_columns: list<string>, update: string}>
     */
    private function validateAndReadDefinitions(array $expectedRules): array
    {
        $database = DB::getDatabaseName();
        $names = array_keys(self::FOREIGN_KEYS);

        $rules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->whereIn('CONSTRAINT_NAME', $names)
            ->get(['CONSTRAINT_NAME', 'TABLE_NAME', 'REFERENCED_TABLE_NAME', 'UPDATE_RULE', 'DELETE_RULE'])
            ->keyBy('CONSTRAINT_NAME');

        $missing = array_values(array_diff($names, $rules->keys()->all()));
        if ($missing !== []) {
            throw new RuntimeException('Missing Tenant deletion foreign keys: '.implode(', ', $missing));
        }

        foreach (self::FOREIGN_KEYS as $name => $expected) {
            $rule = $rules->get($name);
            if ($rule->TABLE_NAME !== $expected['table']) {
                throw new RuntimeException("Unexpected child table for {$name}.");
            }

            if ($rule->DELETE_RULE !== $expectedRules[$name]) {
                throw new RuntimeException("Unexpected delete rule for {$name}: {$rule->DELETE_RULE}.");
            }
        }

        $this->assertNoAdditionalTenantForeignKeys($database, $names);
        $this->assertGlobalUserForeignKeysRemainRestricted($database);

        $tenantRule = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('CONSTRAINT_NAME', 'tenant_companies_company_id_foreign')
            ->first(['DELETE_RULE']);

        if ($tenantRule === null || $tenantRule->DELETE_RULE !== 'CASCADE') {
            throw new RuntimeException('tenant_companies_company_id_foreign must use ON DELETE CASCADE.');
        }

        /** @var Collection<string, Collection<int, object>> $columns */
        $columns = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->whereIn('CONSTRAINT_NAME', $names)
            ->orderBy('ORDINAL_POSITION')
            ->get(['CONSTRAINT_NAME', 'COLUMN_NAME', 'REFERENCED_COLUMN_NAME'])
            ->groupBy('CONSTRAINT_NAME');

        $definitions = [];
        foreach ($names as $name) {
            $rule = $rules->get($name);
            $constraintColumns = $columns->get($name);
            if ($constraintColumns === null || $constraintColumns->isEmpty()) {
                throw new RuntimeException("Missing columns for {$name}.");
            }

            $definitions[$name] = [
                'table' => $rule->TABLE_NAME,
                'referenced_table' => $rule->REFERENCED_TABLE_NAME,
                'columns' => $constraintColumns->pluck('COLUMN_NAME')->values()->all(),
                'referenced_columns' => $constraintColumns->pluck('REFERENCED_COLUMN_NAME')->values()->all(),
                'update' => $rule->UPDATE_RULE,
            ];
        }

        return $definitions;
    }

    /** @param list<string> $expectedNames */
    private function assertNoAdditionalTenantForeignKeys(string $database, array $expectedNames): void
    {
        $tenantTables = array_values(array_unique(array_column(self::FOREIGN_KEYS, 'table')));
        $actualNames = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->whereIn('TABLE_NAME', $tenantTables)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->where('REFERENCED_TABLE_NAME', '<>', 'users')
            ->distinct()
            ->pluck('CONSTRAINT_NAME')
            ->all();

        $expectedNames[] = 'tenant_companies_company_id_foreign';
        $additional = array_values(array_diff($actualNames, $expectedNames));
        if ($additional !== []) {
            throw new RuntimeException('Uncensused Tenant foreign keys: '.implode(', ', $additional));
        }
    }

    private function assertGlobalUserForeignKeysRemainRestricted(string $database): void
    {
        $invalid = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('REFERENCED_TABLE_NAME', 'users')
            ->where('DELETE_RULE', '<>', 'RESTRICT')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        if ($invalid !== []) {
            throw new RuntimeException('Global User foreign keys must remain RESTRICT: '.implode(', ', $invalid));
        }
    }

    /**
     * @param  array{table: string, referenced_table: string, columns: list<string>, referenced_columns: list<string>, update: string}  $definition
     */
    private function replaceForeignKey(string $name, array $definition, string $deleteRule): void
    {
        $table = $this->identifier($definition['table']);
        $constraint = $this->identifier($name);
        $referencedTable = $this->identifier($definition['referenced_table']);
        $columns = implode(', ', array_map(fn (string $column): string => $this->identifier($column), $definition['columns']));
        $referencedColumns = implode(', ', array_map(fn (string $column): string => $this->identifier($column), $definition['referenced_columns']));

        DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$columns}) "
            ."REFERENCES {$referencedTable} ({$referencedColumns}) ON DELETE {$deleteRule} ON UPDATE {$definition['update']}",
        );
    }

    private function replaceExpenseGeneratedColumns(string $from, string $to): void
    {
        $database = DB::getDatabaseName();
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'expenses')
            ->whereIn('COLUMN_NAME', ['generated_contract_id', 'generated_exercise_id'])
            ->pluck('EXTRA', 'COLUMN_NAME');

        if ($columns->count() !== 2 || $columns->contains(fn (string $extra): bool => $extra !== "{$from} GENERATED")) {
            throw new RuntimeException("Expense generated columns must both be {$from} before migration.");
        }

        $indexColumns = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'expenses')
            ->where('INDEX_NAME', 'expenses_generated_contract_exercise_unique')
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();

        if ($indexColumns !== ['generated_contract_id', 'generated_exercise_id']) {
            throw new RuntimeException('Unexpected expenses_generated_contract_exercise_unique definition.');
        }

        DB::statement(
            'ALTER TABLE expenses '
            .'DROP INDEX expenses_generated_contract_exercise_unique, '
            .'DROP COLUMN generated_contract_id, '
            .'DROP COLUMN generated_exercise_id, '
            ."ADD COLUMN generated_contract_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN origin = 'system' THEN contract_id ELSE NULL END) {$to}, "
            ."ADD COLUMN generated_exercise_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN origin = 'system' THEN exercise_id ELSE NULL END) {$to}, "
            .'ADD UNIQUE INDEX expenses_generated_contract_exercise_unique (generated_contract_id, generated_exercise_id)',
        );
    }

    private function replaceProjectContractLinkGeneratedColumn(string $from, string $to): void
    {
        $database = DB::getDatabaseName();
        $extra = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'project_contract_links')
            ->where('COLUMN_NAME', 'active_contract_id')
            ->value('EXTRA');

        if ($extra !== "{$from} GENERATED") {
            throw new RuntimeException("project_contract_links.active_contract_id must be {$from} before migration.");
        }

        $indexColumns = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'project_contract_links')
            ->where('INDEX_NAME', 'project_contract_links_active_unique')
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();

        if ($indexColumns !== ['project_id', 'active_contract_id']) {
            throw new RuntimeException('Unexpected project_contract_links_active_unique definition.');
        }

        DB::statement(
            'ALTER TABLE project_contract_links '
            .'DROP INDEX project_contract_links_active_unique, '
            .'DROP COLUMN active_contract_id, '
            ."ADD COLUMN active_contract_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN archived_at IS NULL THEN contract_id ELSE NULL END) {$to}, "
            .'ADD UNIQUE INDEX project_contract_links_active_unique (project_id, active_contract_id)',
        );
    }

    /** @param array<string, string> $expectedRules */
    private function assertDeleteRules(array $expectedRules): void
    {
        $actual = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('CONSTRAINT_NAME', array_keys($expectedRules))
            ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')
            ->all();

        ksort($actual);
        ksort($expectedRules);

        if ($actual !== $expectedRules) {
            throw new RuntimeException('Tenant deletion foreign-key rules do not match the required matrix.');
        }

        $this->assertGlobalUserForeignKeysRemainRestricted(DB::getDatabaseName());
    }

    private function identifier(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException("Invalid SQL identifier: {$value}.");
        }

        return "`{$value}`";
    }
};
