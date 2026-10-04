<?php

namespace App\Actions\BusinessBackup;

use App\Actions\Tenancy\DeletePendingTenantFiles;
use App\Actions\Tenancy\DestroyTenantCompanyData;
use App\BusinessBackup\V1\BusinessBackupContract;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\BusinessBackupImport;
use App\Models\Company;
use App\Models\PendingFileDeletion;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ImportBusinessBackup
{
    /** @param array{
     *   manifest: array<string, string>,
     *   machine: array<string, array{columns: list<string>, rows: list<list<string>>}>
     * } $package
     */
    public function execute(User $actor, array $package, ?string $importOperationId = null, string $operation = 'create', ?int $expectedTargetCompanyId = null, bool $irreversibilityConfirmed = false, bool $destructionConfirmed = false): Company
    {
        if (! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Solo un Platform Admin può importare un backup.');
        }
        $packageId = $package['manifest']['package_id'];
        $v3 = $package['manifest']['format_version'] === '3';
        if ($v3 && $importOperationId === null) {
            throw ValidationException::withMessages(['backup' => 'Identificativo operazione di import obbligatorio.']);
        }
        $importOperationId ??= $packageId;
        if (! Str::isUuid($importOperationId) || ! in_array($operation, ['create', 'copy', 'replace'], true)) {
            throw ValidationException::withMessages(['backup' => 'Operazione di import non valida.']);
        }
        if (! $v3 && $operation !== 'create') {
            throw ValidationException::withMessages(['backup' => 'I backup legacy possono solo creare una nuova Azienda.']);
        }
        $sourceUuid = $v3 ? $package['manifest']['source_tenant_uuid'] : null;
        $targetUuid = $operation === 'copy' || ! $v3 ? (string) Str::uuid() : $sourceUuid;
        $completed = BusinessBackupImport::query()->where('import_operation_id', $importOperationId)->with('company.tenantCompany')->first();
        if ($completed !== null) {
            return $this->completedCompany($completed, $packageId);
        }

        $filePreparer = app(PrepareBackupFiles::class);
        $files = $filePreparer->execute($package);
        $destroyOperationId = null;
        $committed = false;
        $created = false;
        $proposals = new RestoreBackupProposals;
        try {
            $company = DB::transaction(function () use ($actor, $package, $packageId, $importOperationId, $operation, $expectedTargetCompanyId, $irreversibilityConfirmed, $destructionConfirmed, $sourceUuid, $targetUuid, $v3, $files, $proposals, &$destroyOperationId, &$created): Company {
                $completed = BusinessBackupImport::query()->where('import_operation_id', $importOperationId)->lockForUpdate()->first();
                if ($completed !== null) {
                    return $this->completedCompany($completed, $packageId);
                }

                if ($v3 && $operation !== 'copy') {
                    $target = TenantCompany::query()->where('portable_uuid', $sourceUuid)->first();
                    if ($operation === 'replace') {
                        if ($target === null || $target->company_id !== $expectedTargetCompanyId || ! $irreversibilityConfirmed || ! $destructionConfirmed) {
                            throw ValidationException::withMessages(['backup' => 'Il Tenant target è cambiato oppure manca la doppia conferma. Generare una nuova anteprima.']);
                        }
                        $targetCompany = Company::query()->lockForUpdate()->find($target->company_id);
                        $target = $targetCompany === null ? null : TenantCompany::query()->lockForUpdate()->find($targetCompany->id);
                        if ($target === null || $target->portable_uuid !== $sourceUuid || $target->company_id !== $expectedTargetCompanyId) {
                            throw ValidationException::withMessages(['backup' => 'Il Tenant target è cambiato. Generare una nuova anteprima.']);
                        }
                        $destroyOperationId = app(DestroyTenantCompanyData::class)->execute($actor, $targetCompany, $target);
                    } elseif ($target !== null) {
                        throw ValidationException::withMessages(['backup' => 'Identità Tenant già presente. Generare una nuova anteprima e scegliere Copia o Sostituisci.']);
                    }
                }
                $now = now('UTC');
                $companyRow = $this->rows($package, '_MP2_company')[0];
                $companyId = DB::table('companies')->insertGetId([
                    'name' => $companyRow['name'], 'timezone' => $companyRow['timezone'],
                    'overspend_note_required' => (int) $companyRow['overspend_note_required'],
                    'unclassified_closing_policy' => $companyRow['unclassified_closing_policy'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('tenant_companies')->insert(['company_id' => $companyId, 'status' => TenantCompanyStatus::Active->value, 'portable_uuid' => $targetUuid, 'created_at' => $now, 'updated_at' => $now]);
                $ids = [];
                $ids['company']['COM-0000000001'] = $companyId;
                $this->insertBasic($package, '_MP2_suppliers', 'supplier', 'suppliers', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'legal_name' => $x['legal_name'], 'vat_number' => $this->null($x['vat_number']),
                    'notes' => $this->null($x['notes']), 'archived_at' => $this->null($x['archived_at']),
                ]);
                $this->insertBasic($package, '_MP2_supplier_contacts', 'contact', 'supplier_contacts', $ids, $companyId, $now, fn (array $x): array => [
                    'supplier_id' => $this->id($ids, 'supplier', $x['supplier_ref']), 'first_name' => $this->null($x['first_name']),
                    'last_name' => $this->null($x['last_name']), 'phone' => $this->null($x['phone']), 'email' => $this->null($x['email']),
                    'notes' => $this->null($x['notes']), 'role_tags' => $x['role_tags_json'],
                ]);
                $this->insertBasic($package, '_MP2_cost_centers', 'cost_center', 'cost_centers', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'name' => $x['name'], 'archived_at' => $this->null($x['archived_at']),
                ]);
                foreach ($this->rows($package, '_MP2_cost_centers') as $x) {
                    if ($x['parent_cost_center_ref'] === '') {
                        continue;
                    }
                    DB::table('cost_centers')->where('id', $this->id($ids, 'cost_center', $x['cost_center_ref']))->update([
                        'parent_id' => $this->id($ids, 'cost_center', $x['parent_cost_center_ref']),
                        'updated_at' => $now,
                    ]);
                }
                $this->insertBasic($package, '_MP2_exercises', 'exercise', 'exercises', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'year' => (int) $x['year'], 'status' => $x['status'], 'revision' => 0,
                ]);
                $this->insertBasic($package, '_MP2_projects', 'project', 'projects', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'title' => $x['title'], 'description' => $this->null($x['description']),
                    'notes' => $this->null($x['notes']), 'initial_state' => $x['initial_state'],
                    'initial_effective_date' => $x['initial_effective_date'], 'archived_at' => $this->null($x['archived_at']), 'revision' => 0,
                ]);
                $this->insertBasic($package, '_MP2_project_transitions', 'project_transition', 'project_transitions', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'project_id' => $this->id($ids, 'project', $x['project_ref']),
                    'from_state' => $x['from_state'], 'to_state' => $x['to_state'], 'effective_date' => $x['effective_date'],
                    'reason' => $this->null($x['reason']), 'created_by_id' => null, 'annulled_at' => $this->null($x['annulled_at']),
                    'annulled_by_id' => null, 'annulment_reason' => $this->null($x['annulment_reason']),
                ]);
                $this->insertBasic($package, '_MP2_project_classes', 'project_classification', 'project_exercise_classifications', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'project_id' => $this->id($ids, 'project', $x['project_ref']),
                    'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']),
                    'cost_center_id' => $this->optionalId($ids, 'cost_center', $x['cost_center_ref']),
                ]);

                $this->insertBasic($package, '_MP2_contracts', 'contract', 'contracts', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'supplier_id' => $this->id($ids, 'supplier', $x['supplier_ref']),
                    'title' => $x['title'], 'notes' => $this->null($x['notes']), 'contractual_start_date' => $x['contractual_start_date'],
                    'next_expiry_date' => $this->null($x['next_expiry_date']), 'renewal_anchor_date' => $this->null($x['renewal_anchor_date']),
                    'automatic_renewal' => (int) $x['automatic_renewal'], 'renewal_duration_months' => $this->nullableInt($x['renewal_duration_months']),
                    'notice_days' => $this->nullableInt($x['notice_days']), 'archived_at' => $this->null($x['archived_at']), 'revision' => 0,
                ]);
                $this->insertBasic($package, '_MP2_contract_renewals', 'contract_renewal', 'contract_renewal_configurations', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'contract_id' => $this->id($ids, 'contract', $x['contract_ref']),
                    'effective_from' => $x['effective_from'], 'automatic_renewal' => (int) $x['automatic_renewal'],
                    'expiry_anchor_date' => $this->null($x['expiry_anchor_date']), 'renewal_duration_months' => $this->nullableInt($x['renewal_duration_months']),
                    'notice_days' => $this->nullableInt($x['notice_days']), 'created_by_id' => null,
                ]);
                $this->insertBasic($package, '_MP2_contract_lifecycle', 'contract_lifecycle', 'contract_lifecycle_facts', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'contract_id' => $this->id($ids, 'contract', $x['contract_ref']), 'type' => $x['type'],
                    'declared_contractual_date' => $x['declared_contractual_date'], 'state_change_date' => $this->null($x['state_change_date']),
                    'renewed_expiry_date' => $this->null($x['renewed_expiry_date']),
                    'renewal_configuration_id' => $this->optionalId($ids, 'contract_renewal', $x['renewal_ref']),
                    'reason' => $this->null($x['reason']), 'created_by_id' => null, 'annulled_at' => $this->null($x['annulled_at']),
                    'annulled_by_id' => null, 'annulment_reason' => $this->null($x['annulment_reason']),
                ]);
                $this->insertBasic($package, '_MP2_contract_conditions', 'contract_condition', 'contract_conditions', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'contract_id' => $this->id($ids, 'contract', $x['contract_ref']),
                    'cycle' => $x['cycle'], 'attribution_mode' => $x['attribution_mode'], 'amount' => $x['amount'],
                    'valid_from' => $x['valid_from'], 'valid_to' => $this->null($x['valid_to']), 'reason' => $this->null($x['reason']),
                    'created_by_id' => null, 'annulled_at' => $this->null($x['annulled_at']), 'annulled_by_id' => null,
                ]);
                $this->insertBasic($package, '_MP2_contract_classes', 'contract_classification', 'contract_exercise_classifications', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'contract_id' => $this->id($ids, 'contract', $x['contract_ref']),
                    'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']),
                    'cost_center_id' => $this->optionalId($ids, 'cost_center', $x['cost_center_ref']),
                ]);
                $this->insertBasic($package, '_MP2_project_contract_links', 'project_contract_link', 'project_contract_links', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'project_id' => $this->id($ids, 'project', $x['project_ref']),
                    'contract_id' => $this->id($ids, 'contract', $x['contract_ref']), 'note' => $this->null($x['note']),
                    'archived_at' => $this->null($x['archived_at']), 'revision' => 0,
                ]);

                $expenseRows = $this->rows($package, '_MP2_expenses');
                foreach ($expenseRows as $x) {
                    $id = DB::table('expenses')->insertGetId([
                        'company_id' => $companyId, 'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']),
                        'project_id' => $this->optionalId($ids, 'project', $x['project_ref']), 'contract_id' => $this->optionalId($ids, 'contract', $x['contract_ref']),
                        'origin' => $x['origin'], 'copied_from_origin_key' => null,
                        'supplier_id' => $this->optionalId($ids, 'supplier', $x['supplier_ref']),
                        'direct_cost_center_id' => $this->optionalId($ids, 'cost_center', $x['direct_cost_center_ref']),
                        'description' => $x['description'], 'notes' => $this->null($x['notes']), 'reversed_at' => $this->null($x['reversed_at']),
                        'revision' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $ids['expense'][$x['expense_ref']] = $id;
                }
                foreach ($expenseRows as $x) {
                    if ($x['copied_from_expense_ref'] !== '') {
                        DB::table('expenses')->where('id', $this->id($ids, 'expense', $x['expense_ref']))->update([
                            'copied_from_origin_key' => $this->origin($ids, $x['copied_from_expense_ref']),
                        ]);
                    }
                }
                $this->insertBasic($package, '_MP2_expense_lines', 'expense_line', 'expense_lines', $ids, $companyId, $now, fn (array $x): array => [
                    'expense_id' => $this->id($ids, 'expense', $x['expense_ref']), 'type' => $x['type'], 'amount' => $x['amount'],
                    'quantity' => $this->null($x['quantity']), 'unit_amount' => $this->null($x['unit_amount']),
                    'unit_of_measure' => $this->null($x['unit_of_measure']), 'note' => $this->null($x['note']),
                    'annulled_at' => $this->null($x['annulled_at']), 'revision' => 0,
                ]);
                $this->insertBasic($package, '_MP2_project_deferrals', 'project_deferral', 'project_deferrals', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'project_id' => $this->id($ids, 'project', $x['project_ref']),
                    'source_exercise_id' => $this->id($ids, 'exercise', $x['source_exercise_ref']),
                    'destination_exercise_id' => $this->id($ids, 'exercise', $x['destination_exercise_ref']),
                    'mode' => $x['mode'], 'carryover_amount' => $x['carryover_amount'], 'carryover_state' => $this->null($x['carryover_state']),
                    'reprogrammed_amount' => $x['reprogrammed_amount'],
                    'reprogramming_operation_id' => $x['mode'] === 'reprogramming' ? (string) Str::uuid() : null,
                    'reprogramming_effects' => $x['mode'] === 'reprogramming'
                        ? json_encode($this->hydrateEffects(json_decode($x['reprogramming_effects_json'], true, flags: JSON_THROW_ON_ERROR), $ids, $companyId, $x), JSON_THROW_ON_ERROR)
                        : null,
                ]);

                if ($v3) {
                    $proposals->headersAndItems($package, $ids, $companyId);
                }

                $budgetRows = collect($this->rows($package, '_MP2_budgets'))->sortBy(fn (array $x): int => (int) $x['version'])->values()->all();
                foreach ($budgetRows as $x) {
                    $id = DB::table('budget_snapshots')->insertGetId([
                        'company_id' => $companyId, 'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']),
                        'proposal_id' => $this->optionalId($ids, 'proposal', $x['proposal_ref'] ?? ''), 'version' => (int) $x['version'], 'purpose' => $x['purpose'],
                        'approved_at' => $x['approved_at'], 'approved_by_id' => null,
                        'previous_budget_id' => $this->optionalId($ids, 'budget', $x['previous_budget_ref']),
                        'total_approved_allocation' => $x['total'],
                        'affected_exercises' => json_encode($this->hydrateJson(json_decode($x['affected_exercises_json'], true, flags: JSON_THROW_ON_ERROR), $ids), JSON_THROW_ON_ERROR),
                        'operation_id' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $ids['budget'][$x['budget_ref']] = $id;
                }
                if ($v3) {
                    $proposals->actionsAndReferences($package, $ids, $companyId);
                }
                foreach ($this->rows($package, '_MP2_budget_rows') as $x) {
                    $proposalItemId = ($x['proposal_item_ref'] ?? '') === '' ? (string) Str::uuid() : $ids['proposal_item_uuid'][$x['proposal_item_ref']];
                    $detail = $this->hydrateJson(json_decode($x['detail_json'], true, flags: JSON_THROW_ON_ERROR), $ids);
                    if (is_array($detail)) {
                        if (isset($detail['identity']) && is_array($detail['identity'])) {
                            $detail['identity']['proposal_item_id'] = $proposalItemId;
                        }
                    }
                    $id = DB::table('budget_source_rows')->insertGetId([
                        'company_id' => $companyId, 'budget_snapshot_id' => $this->id($ids, 'budget', $x['budget_ref']),
                        'source_type' => $x['source_type'], 'origin_id' => $this->id($ids, $x['source_type'], $x['source_ref']),
                        'origin_key' => $this->origin($ids, $x['source_ref']), 'proposal_item_id' => $proposalItemId,
                        'copied_from_origin_key' => $this->optionalOrigin($ids, $x['copied_from_source_ref']),
                        'label' => $x['label'], 'summary' => $this->null($x['summary']),
                        'supplier_id' => $this->optionalId($ids, 'supplier', $x['supplier_ref']), 'supplier_label' => $this->null($x['supplier_label']),
                        'cost_center_id' => $this->optionalId($ids, 'cost_center', $x['cost_center_ref']), 'cost_center_label' => $x['cost_center_label'],
                        'approved_estimates' => $x['approved_estimates'], 'approved_carryover' => $x['approved_carryover'],
                        'carryover_state' => $this->null($x['carryover_state']), 'approved_allocation' => $x['approved_allocation'],
                        'start_state' => $this->null($x['start_state']), 'end_state' => $this->null($x['end_state']),
                        'detail_version' => (int) $x['detail_version'], 'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $ids['budget_row'][$x['budget_row_ref']] = $id;
                }

                foreach ($this->rows($package, '_MP2_closings') as $x) {
                    $id = DB::table('closing_snapshots')->insertGetId([
                        'company_id' => $companyId, 'company_name' => $x['company_name'],
                        'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']), 'exercise_year' => (int) $x['exercise_year'],
                        'closed_at' => $x['closed_at'], 'closed_by_id' => null,
                        'initial_budget_id' => $this->optionalId($ids, 'budget', $x['initial_budget_ref']),
                        'current_budget_id' => $this->optionalId($ids, 'budget', $x['current_budget_ref']),
                        'total_final_allocation' => $x['total_final_allocation'], 'total_closing_actual' => $x['total_closing_actual'],
                        'total_operational_variance' => $x['total_operational_variance'], 'total_consolidated_carryover' => $x['total_consolidated_carryover'],
                        'accepted_warnings' => json_encode($this->hydrateJson(json_decode($x['warnings_json'], true, flags: JSON_THROW_ON_ERROR), $ids), JSON_THROW_ON_ERROR),
                        'applied_settings' => json_encode($this->hydrateJson(json_decode($x['settings_json'], true, flags: JSON_THROW_ON_ERROR), $ids), JSON_THROW_ON_ERROR),
                        'next_exercise_disposition' => $x['next_exercise_disposition'],
                        'next_exercise_id' => $this->optionalId($ids, 'exercise', $x['next_exercise_ref']),
                        'operation_id' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $ids['closing'][$x['closing_ref']] = $id;
                }
                $this->insertBasic($package, '_MP2_closing_rows', 'closing_row', 'closing_source_rows', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'closing_snapshot_id' => $this->id($ids, 'closing', $x['closing_ref']),
                    'source_type' => $x['source_type'], 'origin_id' => $this->id($ids, $x['source_type'], $x['source_ref']),
                    'origin_key' => $this->origin($ids, $x['source_ref']), 'copied_from_origin_key' => $this->optionalOrigin($ids, $x['copied_from_source_ref']),
                    'label' => $x['label'], 'summary' => $this->null($x['summary']),
                    'supplier_id' => $this->optionalId($ids, 'supplier', $x['supplier_ref']), 'supplier_label' => $this->null($x['supplier_label']),
                    'cost_center_id' => $this->optionalId($ids, 'cost_center', $x['cost_center_ref']), 'cost_center_label' => $x['cost_center_label'],
                    'end_state' => $this->null($x['end_state']), 'has_actuals' => (int) $x['has_actuals'],
                    'final_estimates' => $x['final_estimates'], 'received_carryover' => $x['received_carryover'],
                    'final_allocation' => $x['final_allocation'], 'closing_actual' => $x['closing_actual'],
                    'operational_variance' => $x['operational_variance'], 'detail_version' => (int) $x['detail_version'],
                    'detail' => json_encode($this->hydrateJson(json_decode($x['detail_json'], true, flags: JSON_THROW_ON_ERROR), $ids), JSON_THROW_ON_ERROR),
                ]);

                $this->insertBasic($package, '_MP2_late_corrections', 'late_correction', 'late_corrections', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']),
                    'closing_snapshot_id' => $this->id($ids, 'closing', $x['closing_ref']), 'expense_id' => $this->id($ids, 'expense', $x['expense_ref']),
                    'expense_line_id' => $this->id($ids, 'expense_line', $x['line_ref']),
                    'original_expense_line_id' => $this->optionalId($ids, 'expense_line', $x['original_line_ref']),
                    'recorded_by_id' => null, 'operation_id' => (string) Str::uuid(), 'reason' => $x['reason'],
                    'belongs_to_closed_exercise' => (int) $x['belongs_to_closed_exercise'], 'source_type' => $x['source_type'],
                    'source_origin_id' => $this->id($ids, $x['source_type'], $x['source_ref']), 'source_origin_key' => $this->origin($ids, $x['source_ref']),
                    'source_label' => $x['label'], 'owner_context' => json_encode($this->hydrateJson(json_decode($x['owner_context_json'], true, flags: JSON_THROW_ON_ERROR), $ids), JSON_THROW_ON_ERROR),
                    'supplier_context' => $x['supplier_context_json'] === '' ? null : json_encode($this->hydrateJson(json_decode($x['supplier_context_json'], true, flags: JSON_THROW_ON_ERROR), $ids), JSON_THROW_ON_ERROR),
                    'created_at' => $x['recorded_at'],
                ], false);
                $this->insertAnnotations($package, $ids, $companyId);
                $this->insertAttachments($package, $ids, $companyId, $files);
                $this->insertBasic($package, '_MP2_budget_evidence', 'budget_evidence', 'budget_evidence', $ids, $companyId, $now, fn (array $x): array => [
                    'company_id' => $companyId, 'budget_snapshot_id' => $this->id($ids, 'budget', $x['budget_ref']),
                    'external_subject' => $this->null($x['external_subject']), 'external_venue' => $this->null($x['external_venue']),
                    'reason' => $this->null($x['reason']),
                    ...$this->evidenceFile($x, $ids, $files),
                    'original_name' => $this->null($x['original_name']), 'media_type' => $this->null($x['media_type']),
                    'size_bytes' => $this->nullableInt($x['size']), 'sha256' => $this->null($x['sha256']),
                ]);
                if (isset($files['logo:COM-0000000001'])) {
                    $logo = $files['logo:COM-0000000001'];
                    DB::table('companies')->where('id', $companyId)->update(['logo_disk' => $logo['disk'], 'logo_path' => $logo['path'], 'logo_media_type' => $logo['media_type']]);
                }
                if ($v3) {
                    $proposals->rebuildDrafts($companyId);
                }

                $this->verifyCounts($package, $companyId);
                DB::table('business_backup_imports')->insert([
                    'package_id' => $packageId, 'format_version' => (int) $package['manifest']['format_version'], 'company_id' => $companyId,
                    'import_operation_id' => $importOperationId, 'operation' => $operation, 'target_tenant_uuid' => $targetUuid, 'source_tenant_uuid' => $sourceUuid,
                    'imported_by_id' => $actor->id, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $created = true;

                $result = Company::query()->with('tenantCompany')->findOrFail($companyId);
                if ($destroyOperationId !== null) {
                    $result->setAttribute('backup_cleanup_pending', PendingFileDeletion::query()->where('operation_id', $destroyOperationId)->count());
                }

                return $result;
            }, 1);
            $committed = $created;
        } catch (QueryException $exception) {
            $committed = false;
            $completed = BusinessBackupImport::query()->where('import_operation_id', $importOperationId)->with('company.tenantCompany')->first();
            if ($completed !== null) {
                return $this->completedCompany($completed, $packageId);
            }

            if ($v3 && str_contains($exception->getMessage(), 'tenant_companies_portable_uuid_unique')) {
                throw ValidationException::withMessages(['backup' => 'L’identità Tenant è cambiata durante l’import. Generare una nuova anteprima.']);
            }
            throw $exception;
        } finally {
            if (! $committed) {
                $filePreparer->cleanup($files);
            }
        }
        if ($destroyOperationId !== null) {
            DB::afterCommit(function () use ($destroyOperationId, $company): void {
                try {
                    app(DeletePendingTenantFiles::class)->execute($destroyOperationId);
                    $company->setAttribute('backup_cleanup_pending', PendingFileDeletion::query()->where('operation_id', $destroyOperationId)->count());
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        }

        return $company;
    }

    private function completedCompany(BusinessBackupImport $receipt, string $packageId): Company
    {
        if ($receipt->package_id !== $packageId) {
            throw ValidationException::withMessages(['backup' => 'Identificativo operazione già usato per un altro package.']);
        }
        $company = $receipt->company()->with('tenantCompany')->first();
        if ($company === null) {
            throw ValidationException::withMessages(['backup' => 'Operazione già completata; il Tenant risultante è stato successivamente eliminato.']);
        }

        return $company;
    }

    /** @param array<string, mixed> $package
     * @param  array<string, array<string, int|string>>  $ids
     * @param  array<string, array{disk: string, path: string, media_type: ?string}>  $files
     */
    private function insertAttachments(array $package, array &$ids, int $companyId, array $files): void
    {
        foreach ($this->rows($package, '_MP2_attachments') as $row) {
            $file = $files['attachment:'.$row['attachment_ref']] ?? null;
            if ($file === null) {
                continue;
            }
            $ownerType = $row['owner_type'] === 'historical_error_annotation' ? 'annotation' : $row['owner_type'];
            $ids['attachment'][$row['attachment_ref']] = DB::table('attachments')->insertGetId([
                'company_id' => $companyId, $row['owner_type'].'_id' => $this->id($ids, $ownerType, $row['owner_ref']),
                'storage_disk' => $file['disk'], 'storage_path' => $file['path'],
                'original_name' => $row['original_name'], 'media_type' => $row['media_type'] ?: null,
                'size_bytes' => (int) $row['size'], 'sha256' => $row['sha256'], 'uploaded_by_id' => null,
                'detached_at' => $row['detached_at'] ?: null, 'detached_by_id' => null,
                'created_at' => $row['uploaded_at'], 'updated_at' => $row['detached_at'] ?: $row['uploaded_at'],
            ]);
        }
    }

    /** @param array<string, string> $row
     * @param  array<string, array<string, int|string>>  $ids
     * @param  array<string, array{disk: string, path: string, media_type: ?string}>  $files
     * @return array<string, mixed>
     */
    private function evidenceFile(array $row, array $ids, array $files): array
    {
        $file = $row['attachment_ref'] === '' ? ($files['budget_evidence:'.$row['evidence_ref']] ?? null) : ($files['attachment:'.$row['attachment_ref']] ?? null);

        return ['attachment_id' => $file !== null && $row['attachment_ref'] !== '' ? $ids['attachment'][$row['attachment_ref']] : null, 'storage_disk' => $file['disk'] ?? null, 'storage_path' => $file['path'] ?? null];
    }

    /** @param array<string, mixed> $package
     * @param  array<string, array<string, int|string>>  $ids
     */
    private function insertAnnotations(array $package, array &$ids, int $companyId): void
    {
        foreach ($this->rows($package, '_MP2_error_annotations') as $x) {
            $versions = json_decode($x['versions_json'], true, flags: JSON_THROW_ON_ERROR);
            $facts = json_decode($x['facts_json'], true, flags: JSON_THROW_ON_ERROR);
            $sources = [];
            foreach ($this->objectList(json_decode($x['affected_source_refs_json'], true, flags: JSON_THROW_ON_ERROR)) as $source) {
                $sources[] = [
                    'type' => $source['type'], 'id' => $this->id($ids, $this->sourceType($source['type']), $source['ref']),
                    'origin_key' => $this->origin($ids, $source['ref'], $source['type']), 'label' => $source['label'],
                ];
            }
            $id = DB::table('historical_error_annotations')->insertGetId([
                'company_id' => $companyId, 'exercise_id' => $this->id($ids, 'exercise', $x['exercise_ref']),
                'closing_snapshot_id' => $this->id($ids, 'closing', $x['closing_ref']), 'recorded_by_id' => null,
                'operation_id' => (string) Str::uuid(), 'kind' => $x['kind'], 'reason' => $x['reason'],
                'recorded_facts_version' => (int) $versions['recorded_facts'],
                'recorded_facts' => json_encode($this->hydrateJson($facts['recorded'], $ids), JSON_THROW_ON_ERROR),
                'believed_correct_facts_version' => (int) $versions['believed_correct_facts'],
                'believed_correct_facts' => json_encode($this->hydrateJson($facts['believed_correct'], $ids), JSON_THROW_ON_ERROR),
                'affected_sources_version' => (int) $versions['affected_sources'],
                'affected_sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'created_at' => $x['recorded_at'],
            ]);
            $ids['annotation'][$x['annotation_ref']] = $id;
        }
    }

    /** @param array<string, mixed> $package
     * @param  array<string, array<string, int|string>>  $ids
     * @param  callable(array<string, string>): array<string, mixed>  $attributes
     */
    private function insertBasic(array $package, string $sheet, string $type, string $table, array &$ids, int $companyId, mixed $now, callable $attributes, bool $timestamps = true): void
    {
        foreach ($this->rows($package, $sheet) as $row) {
            $values = $attributes($row);
            if ($timestamps) {
                $values += ['created_at' => $now, 'updated_at' => $now];
            }
            $id = DB::table($table)->insertGetId($values);
            $ids[$type][$row[BusinessBackupContract::SCHEMAS[$sheet][0]]] = $id;
        }
    }

    /** @param array<string, mixed> $package
     * @return list<array<string, string>>
     */
    private function rows(array $package, string $sheet): array
    {
        $columns = $package['machine'][$sheet]['columns'];

        return array_map(fn (array $row): array => array_combine($columns, $row), $package['machine'][$sheet]['rows']);
    }

    /** @param array<string, mixed> $effects
     * @param  array<string, array<string, int|string>>  $ids
     * @param  array<string, string>  $deferral
     * @return array<string, mixed>
     */
    private function hydrateEffects(array $effects, array $ids, int $companyId, array $deferral): array
    {
        $projectId = $this->id($ids, 'project', $deferral['project_ref']);
        $sourceExerciseId = $this->id($ids, 'exercise', $deferral['source_exercise_ref']);
        $destinationExerciseId = $this->id($ids, 'exercise', $deferral['destination_exercise_ref']);

        $sourceLines = [];
        foreach ($this->objectList($effects['source_lines'] ?? null) as $x) {
            $sourceLines[] = [
                'company_id' => $companyId, 'project_id' => $projectId, 'exercise_id' => $sourceExerciseId,
                'expense_id' => $this->id($ids, 'expense', $x['expense_ref']),
                'source_expense_origin_key' => $this->origin($ids, $x['expense_ref']),
                'expense_reversed_after' => (bool) $x['expense_reversed_after'],
                'expense_line_id' => $this->id($ids, 'expense_line', $x['line_ref']), 'line_revision_after' => 0,
                'amount_before' => $x['amount_before'], 'amount_after' => $x['amount_after'],
                'quantity' => $this->null($x['quantity']), 'unit_amount' => $this->null($x['unit_amount']),
                'unit_of_measure' => $this->null($x['unit_of_measure']), 'note' => $this->null($x['note']),
                'annulled_before' => (bool) $x['annulled_before'], 'annulled_after' => (bool) $x['annulled_after'],
            ];
        }
        $destinationExpenses = [];
        foreach ($this->objectList($effects['destination_expenses'] ?? null) as $x) {
            $estimateLines = [];
            foreach ($this->objectList($x['estimate_lines'] ?? null) as $line) {
                $estimateLines[] = [
                    'expense_line_id' => $this->id($ids, 'expense_line', $line['line_ref']), 'line_revision_after' => 0,
                    'amount' => $line['amount'], 'quantity' => $this->null($line['quantity']), 'unit_amount' => $this->null($line['unit_amount']),
                    'unit_of_measure' => $this->null($line['unit_of_measure']), 'note' => $this->null($line['note']), 'annulled' => (bool) $line['annulled'],
                ];
            }
            $destinationExpenses[] = [
                'expense_id' => $this->id($ids, 'expense', $x['expense_ref']), 'company_id' => $companyId,
                'exercise_id' => $destinationExerciseId, 'project_id' => $projectId, 'reversed' => (bool) $x['reversed'],
                'copied_from_origin_key' => $this->optionalOrigin($ids, $x['copied_from_expense_ref']),
                'estimate_lines' => $estimateLines,
            ];
        }

        return [
            'source_lines' => $sourceLines,
            'destination_expenses' => $destinationExpenses,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function objectList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException('Expected a JSON list of objects.');
        }
        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new \UnexpectedValueException('Expected a JSON list of objects.');
            }
        }

        return $value;
    }

    /** @param array<string, array<string, int|string>> $ids */
    private function hydrateJson(mixed $value, array $ids, ?string $parent = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->hydrateJson($item, $ids, $parent), $value);
        }
        $refKeys = [
            'expense_ref' => ['expense_id', 'expense'], 'line_ref' => ['line_id', 'expense_line'],
            'project_ref' => ['project_id', 'project'], 'contract_ref' => ['contract_id', 'contract'],
            'supplier_ref' => ['supplier_id', 'supplier'], 'cost_center_ref' => ['cost_center_id', 'cost_center'],
            'exercise_ref' => ['exercise_id', 'exercise'], 'condition_ref' => ['condition_id', 'contract_condition'],
            'renewal_ref' => ['renewal_configuration_id', 'contract_renewal'],
        ];
        $result = [];
        foreach ($value as $key => $item) {
            if ($key === 'ref' && in_array($parent, ['supplier', 'cost_center'], true)) {
                $result['id'] = $item === '' ? null : $this->id($ids, $parent, $item);

                continue;
            }
            if (isset($refKeys[$key])) {
                [$localKey, $type] = $refKeys[$key];
                $result[$localKey] = $item === '' ? null : $this->id($ids, $type, $item);

                continue;
            }
            if ($key === 'source_ref') {
                $type = (string) ($value['source_type'] ?? '');
                if (isset($ids[$type][$item])) {
                    $result['origin_id'] = $ids[$type][$item];
                    $result['origin_key'] = $this->origin($ids, $item);
                }

                continue;
            }
            if ($key === 'copied_from_source_ref') {
                $result['copied_from_origin_key'] = $this->optionalOrigin($ids, (string) $item);

                continue;
            }
            if (str_ends_with((string) $key, '_source_ref')) {
                $result[str_replace('_source_ref', '_origin_key', (string) $key)] = $this->optionalOrigin($ids, (string) $item);

                continue;
            }
            $result[$key] = $this->hydrateJson($item, $ids, (string) $key);
        }

        return $result;
    }

    /** @param array<string, mixed> $package */
    private function verifyCounts(array $package, int $companyId): void
    {
        foreach ([
            '_MP2_suppliers' => 'suppliers', '_MP2_cost_centers' => 'cost_centers', '_MP2_exercises' => 'exercises',
            '_MP2_projects' => 'projects', '_MP2_contracts' => 'contracts', '_MP2_expenses' => 'expenses',
            '_MP2_project_deferrals' => 'project_deferrals', '_MP2_budgets' => 'budget_snapshots',
            '_MP2_closings' => 'closing_snapshots', '_MP2_late_corrections' => 'late_corrections',
            '_MP2_error_annotations' => 'historical_error_annotations',
            ...(isset($package['machine']['_MP2_proposals']) ? ['_MP2_proposals' => 'proposals', '_MP2_proposal_items' => 'proposal_items', '_MP2_proposal_actions' => 'proposal_actions'] : []),
        ] as $sheet => $table) {
            if (DB::table($table)->where('company_id', $companyId)->count() !== count($package['machine'][$sheet]['rows'])) {
                throw new \RuntimeException("Verifica finale fallita per [$table].");
            }
        }
    }

    /** @param array<string, array<string, int|string>> $ids */
    private function id(array $ids, string $type, string $ref): int
    {
        $id = $ids[$type][$ref] ?? null;
        if (! is_int($id)) {
            throw new \UnexpectedValueException("Riferimento non risolto [$type:$ref].");
        }

        return $id;
    }

    /** @param array<string, array<string, int|string>> $ids */
    private function optionalId(array $ids, string $type, string $ref): ?int
    {
        return $ref === '' ? null : $this->id($ids, $type, $ref);
    }

    /** @param array<string, array<string, int|string>> $ids */
    private function origin(array $ids, string $ref, ?string $sourceType = null): string
    {
        $types = $sourceType === null ? ['expense', 'project', 'contract', 'supplier', 'cost_center', 'exercise', 'closing'] : [$this->sourceType($sourceType)];
        foreach ($types as $type) {
            if (isset($ids[$type][$ref])) {
                $originType = $sourceType === 'closing_snapshot' || $type === 'closing' ? 'closing_snapshot' : $type;

                return $originType.':'.$ids[$type][$ref];
            }
        }

        throw new \UnexpectedValueException("Riferimento senza OriginKey [$ref].");
    }

    /** @param array<string, array<string, int|string>> $ids */
    private function optionalOrigin(array $ids, string $ref): ?string
    {
        return $ref === '' ? null : $this->origin($ids, $ref);
    }

    private function sourceType(string $type): string
    {
        return match ($type) {
            'closing_snapshot' => 'closing',
            default => $type,
        };
    }

    private function null(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function nullableInt(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }
}
