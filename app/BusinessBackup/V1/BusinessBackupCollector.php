<?php

namespace App\BusinessBackup\V1;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessBackupCollector
{
    /**
     * @return array{
     *   package_id: string,
     *   exported_at: string,
     *   company: array{name: string, timezone: string},
     *   machine: array<string, array{columns: list<string>, rows: list<list<string>>}>,
     *   visible: array<string, array{columns: list<string>, rows: list<list<string>>}>
     * }
     */
    public function collect(Company $company, ?string $packageId = null, ?CarbonImmutable $exportedAt = null): array
    {
        $packageId ??= (string) Str::uuid();
        $exportedAt ??= CarbonImmutable::now('UTC');
        $companyId = (int) $company->getKey();

        $data = $this->read($companyId);
        $refs = $this->references($data);
        $machine = $this->machineSheets($company, $data, $refs);

        foreach (BusinessBackupContract::SCHEMAS as $sheet => $columns) {
            $machine[$sheet] ??= ['columns' => $columns, 'rows' => []];
        }

        return [
            'package_id' => $packageId,
            'exported_at' => $exportedAt->toIso8601String(),
            'company' => ['name' => $company->name, 'timezone' => $company->timezone],
            'machine' => $machine,
            'visible' => $this->visibleSheets($company, $machine),
        ];
    }

    /** @return array<string, list<object>> */
    private function read(int $companyId): array
    {
        $companyTables = [
            'suppliers', 'cost_centers', 'exercises', 'projects', 'project_transitions',
            'project_exercise_classifications', 'contracts', 'contract_renewal_configurations',
            'contract_lifecycle_facts', 'contract_conditions', 'contract_exercise_classifications',
            'project_contract_links', 'expenses', 'project_deferrals', 'budget_snapshots',
            'budget_source_rows', 'budget_evidence', 'closing_snapshots', 'closing_source_rows',
            'late_corrections', 'historical_error_annotations', 'attachments',
        ];
        $data = [];
        foreach ($companyTables as $table) {
            $query = DB::table($table)->where('company_id', $companyId)->orderBy('id');
            if ($table === 'attachments') {
                $query->whereNull('proposal_id');
            }
            $data[$table] = $query->get()->all();
        }
        $data['supplier_contacts'] = DB::table('supplier_contacts')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_contacts.supplier_id')
            ->where('suppliers.company_id', $companyId)
            ->orderBy('supplier_contacts.id')
            ->select('supplier_contacts.*')->get()->all();
        $data['expense_lines'] = DB::table('expense_lines')
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.company_id', $companyId)
            ->orderBy('expense_lines.id')
            ->select('expense_lines.*')->get()->all();

        return $data;
    }

    /** @param array<string, list<object>> $data */
    private function references(array $data): PortableReferences
    {
        $refs = new PortableReferences;
        $refs->register('company', [1]);
        foreach ([
            'supplier' => 'suppliers', 'contact' => 'supplier_contacts', 'cost_center' => 'cost_centers',
            'exercise' => 'exercises', 'project' => 'projects', 'project_transition' => 'project_transitions',
            'project_classification' => 'project_exercise_classifications', 'contract' => 'contracts',
            'contract_renewal' => 'contract_renewal_configurations', 'contract_lifecycle' => 'contract_lifecycle_facts',
            'contract_condition' => 'contract_conditions', 'contract_classification' => 'contract_exercise_classifications',
            'project_contract_link' => 'project_contract_links', 'expense' => 'expenses', 'expense_line' => 'expense_lines',
            'project_deferral' => 'project_deferrals', 'budget' => 'budget_snapshots', 'budget_row' => 'budget_source_rows',
            'budget_evidence' => 'budget_evidence', 'closing' => 'closing_snapshots', 'closing_row' => 'closing_source_rows',
            'late_correction' => 'late_corrections', 'annotation' => 'historical_error_annotations', 'attachment' => 'attachments',
        ] as $type => $table) {
            $refs->register($type, array_map(fn (object $row): int => (int) $row->id, $data[$table]));
        }

        return $refs;
    }

    /**
     * @param  array<string, list<object>>  $data
     * @return array<string, array{columns: list<string>, rows: list<list<string>>}>
     */
    private function machineSheets(Company $company, array $data, PortableReferences $r): array
    {
        $sheet = fn (string $name, array $rows): array => ['columns' => BusinessBackupContract::SCHEMAS[$name], 'rows' => $rows];
        $money = fn (mixed $value): string => PortablePayload::decimal($value, 2);

        $sheets['_MP2_company'] = $sheet('_MP2_company', [[
            'COM-0000000001', $company->name, $company->timezone, $this->bool($company->overspend_note_required),
            (string) $company->getRawOriginal('unclassified_closing_policy'),
        ]]);
        $sheets['_MP2_suppliers'] = $sheet('_MP2_suppliers', array_map(fn (object $x): array => [
            $r->get('supplier', $x->id), $this->str($x->legal_name), $this->str($x->vat_number),
            $this->str($x->notes), $this->timestamp($x->archived_at),
        ], $data['suppliers']));
        $sheets['_MP2_supplier_contacts'] = $sheet('_MP2_supplier_contacts', array_map(fn (object $x): array => [
            $r->get('contact', $x->id), $r->get('supplier', $x->supplier_id), $this->str($x->first_name),
            $this->str($x->last_name), $this->str($x->phone), $this->str($x->email), $this->str($x->notes),
            PortablePayload::json($this->decode($x->role_tags, [])),
        ], $data['supplier_contacts']));
        $sheets['_MP2_cost_centers'] = $sheet('_MP2_cost_centers', array_map(fn (object $x): array => [
            $r->get('cost_center', $x->id), $this->str($x->name), $this->timestamp($x->archived_at),
        ], $data['cost_centers']));
        $sheets['_MP2_exercises'] = $sheet('_MP2_exercises', array_map(fn (object $x): array => [
            $r->get('exercise', $x->id), (string) $x->year, $this->str($x->status),
        ], $data['exercises']));
        $sheets['_MP2_projects'] = $sheet('_MP2_projects', array_map(fn (object $x): array => [
            $r->get('project', $x->id), $this->str($x->title), $this->str($x->description), $this->str($x->notes),
            $this->str($x->initial_state), $this->date($x->initial_effective_date), $this->timestamp($x->archived_at),
        ], $data['projects']));
        $sheets['_MP2_project_transitions'] = $sheet('_MP2_project_transitions', array_map(fn (object $x): array => [
            $r->get('project_transition', $x->id), $r->get('project', $x->project_id), $this->str($x->from_state),
            $this->str($x->to_state), $this->date($x->effective_date), $this->str($x->reason),
            $this->timestamp($x->annulled_at), $this->str($x->annulment_reason),
        ], $data['project_transitions']));
        $sheets['_MP2_project_classes'] = $sheet('_MP2_project_classes', array_map(fn (object $x): array => [
            $r->get('project_classification', $x->id), $r->get('project', $x->project_id),
            $r->get('exercise', $x->exercise_id), $r->get('cost_center', $x->cost_center_id),
        ], $data['project_exercise_classifications']));
        $sheets['_MP2_contracts'] = $sheet('_MP2_contracts', array_map(fn (object $x): array => [
            $r->get('contract', $x->id), $r->get('supplier', $x->supplier_id), $this->str($x->title),
            $this->str($x->notes), $this->date($x->contractual_start_date), $this->date($x->next_expiry_date),
            $this->date($x->renewal_anchor_date), $this->bool($x->automatic_renewal), $this->str($x->renewal_duration_months),
            $this->str($x->notice_days), $this->timestamp($x->archived_at),
        ], $data['contracts']));
        $sheets['_MP2_contract_renewals'] = $sheet('_MP2_contract_renewals', array_map(fn (object $x): array => [
            $r->get('contract_renewal', $x->id), $r->get('contract', $x->contract_id), $this->date($x->effective_from),
            $this->bool($x->automatic_renewal), $this->date($x->expiry_anchor_date), $this->str($x->renewal_duration_months),
            $this->str($x->notice_days),
        ], $data['contract_renewal_configurations']));
        $sheets['_MP2_contract_lifecycle'] = $sheet('_MP2_contract_lifecycle', array_map(fn (object $x): array => [
            $r->get('contract_lifecycle', $x->id), $r->get('contract', $x->contract_id), $this->str($x->type),
            $this->date($x->declared_contractual_date), $this->date($x->state_change_date), $this->date($x->renewed_expiry_date),
            $r->get('contract_renewal', $x->renewal_configuration_id), $this->str($x->reason),
            $this->timestamp($x->annulled_at), $this->str($x->annulment_reason),
        ], $data['contract_lifecycle_facts']));
        $sheets['_MP2_contract_conditions'] = $sheet('_MP2_contract_conditions', array_map(fn (object $x): array => [
            $r->get('contract_condition', $x->id), $r->get('contract', $x->contract_id), $this->str($x->cycle),
            $this->str($x->attribution_mode), $money($x->amount), $this->date($x->valid_from), $this->date($x->valid_to),
            $this->str($x->reason), $this->timestamp($x->annulled_at),
        ], $data['contract_conditions']));
        $sheets['_MP2_contract_classes'] = $sheet('_MP2_contract_classes', array_map(fn (object $x): array => [
            $r->get('contract_classification', $x->id), $r->get('contract', $x->contract_id),
            $r->get('exercise', $x->exercise_id), $r->get('cost_center', $x->cost_center_id),
        ], $data['contract_exercise_classifications']));
        $sheets['_MP2_project_contract_links'] = $sheet('_MP2_project_contract_links', array_map(fn (object $x): array => [
            $r->get('project_contract_link', $x->id), $r->get('project', $x->project_id),
            $r->get('contract', $x->contract_id), $this->str($x->note), $this->timestamp($x->archived_at),
        ], $data['project_contract_links']));
        $sheets['_MP2_expenses'] = $sheet('_MP2_expenses', array_map(fn (object $x): array => [
            $r->get('expense', $x->id), $r->get('exercise', $x->exercise_id), $r->get('project', $x->project_id),
            $r->get('contract', $x->contract_id), $r->get('supplier', $x->supplier_id),
            $r->get('cost_center', $x->direct_cost_center_id), $this->str($x->origin),
            $r->origin('expense', $x->copied_from_origin_key), $this->str($x->description), $this->str($x->notes),
            $this->timestamp($x->reversed_at),
        ], $data['expenses']));
        $sheets['_MP2_expense_lines'] = $sheet('_MP2_expense_lines', array_map(fn (object $x): array => [
            $r->get('expense_line', $x->id), $r->get('expense', $x->expense_id), $this->str($x->type), $money($x->amount),
            PortablePayload::decimal($x->quantity, 6), PortablePayload::decimal($x->unit_amount, 6),
            $this->str($x->unit_of_measure), $this->str($x->note), $this->timestamp($x->annulled_at),
        ], $data['expense_lines']));
        $sheets['_MP2_project_deferrals'] = $sheet('_MP2_project_deferrals', array_map(fn (object $x): array => [
            $r->get('project_deferral', $x->id), $r->get('project', $x->project_id), $r->get('exercise', $x->source_exercise_id),
            $r->get('exercise', $x->destination_exercise_id), $this->str($x->mode), $money($x->carryover_amount),
            $this->str($x->carryover_state), $money($x->reprogrammed_amount),
            $x->reprogramming_effects === null ? '' : PortablePayload::json($this->portableEffects($this->decode($x->reprogramming_effects, []), $r)),
        ], $data['project_deferrals']));
        $sheets['_MP2_budgets'] = $sheet('_MP2_budgets', array_map(fn (object $x): array => [
            $r->get('budget', $x->id), $r->get('exercise', $x->exercise_id), (string) $x->version, $this->str($x->purpose),
            $this->timestamp($x->approved_at), $r->get('budget', $x->previous_budget_id), $money($x->total_approved_allocation),
            PortablePayload::json($this->portableJson($this->decode($x->affected_exercises, []), $r)),
        ], $data['budget_snapshots']));
        $sheets['_MP2_budget_rows'] = $sheet('_MP2_budget_rows', array_map(fn (object $x): array => [
            $r->get('budget_row', $x->id), $r->get('budget', $x->budget_snapshot_id), $this->str($x->source_type),
            $r->get($x->source_type, $x->origin_id), $r->origin($x->source_type, $x->copied_from_origin_key),
            $this->str($x->label), $this->str($x->summary), $r->get('supplier', $x->supplier_id), $this->str($x->supplier_label),
            $r->get('cost_center', $x->cost_center_id), $this->str($x->cost_center_label), $money($x->approved_estimates),
            $money($x->approved_carryover), $this->str($x->carryover_state), $money($x->approved_allocation),
            $this->str($x->start_state), $this->str($x->end_state), (string) $x->detail_version,
            PortablePayload::json($this->portableJson($this->decode($x->detail, []), $r)),
        ], $data['budget_source_rows']));
        $sheets['_MP2_budget_evidence'] = $sheet('_MP2_budget_evidence', array_map(fn (object $x): array => [
            $r->get('budget_evidence', $x->id), $r->get('budget', $x->budget_snapshot_id), $this->str($x->external_subject),
            $this->str($x->external_venue), $this->str($x->reason), $this->evidenceAttachmentRef($x->attachment_id, $data['attachments'], $r),
            $this->str($x->original_name), $this->str($x->media_type), $this->str($x->size_bytes), $this->str($x->sha256),
        ], $data['budget_evidence']));
        $sheets['_MP2_closings'] = $sheet('_MP2_closings', array_map(fn (object $x): array => [
            $r->get('closing', $x->id), $r->get('exercise', $x->exercise_id), $this->str($x->company_name),
            (string) $x->exercise_year, $this->timestamp($x->closed_at), $r->get('budget', $x->initial_budget_id),
            $r->get('budget', $x->current_budget_id), $money($x->total_final_allocation), $money($x->total_closing_actual),
            $money($x->total_operational_variance), $money($x->total_consolidated_carryover),
            PortablePayload::json($this->portableJson($this->decode($x->accepted_warnings, []), $r)),
            PortablePayload::json($this->portableJson($this->decode($x->applied_settings, []), $r)),
            $this->str($x->next_exercise_disposition), $r->get('exercise', $x->next_exercise_id),
        ], $data['closing_snapshots']));
        $sheets['_MP2_closing_rows'] = $sheet('_MP2_closing_rows', array_map(fn (object $x): array => [
            $r->get('closing_row', $x->id), $r->get('closing', $x->closing_snapshot_id), $this->str($x->source_type),
            $r->get($x->source_type, $x->origin_id), $r->origin($x->source_type, $x->copied_from_origin_key),
            $this->str($x->label), $this->str($x->summary), $r->get('supplier', $x->supplier_id), $this->str($x->supplier_label),
            $r->get('cost_center', $x->cost_center_id), $this->str($x->cost_center_label), $this->str($x->end_state),
            $this->bool($x->has_actuals), $money($x->final_estimates), $money($x->received_carryover),
            $money($x->final_allocation), $money($x->closing_actual), $money($x->operational_variance),
            (string) $x->detail_version, PortablePayload::json($this->portableJson($this->decode($x->detail, []), $r)),
        ], $data['closing_source_rows']));
        $sheets['_MP2_late_corrections'] = $sheet('_MP2_late_corrections', array_map(fn (object $x): array => [
            $r->get('late_correction', $x->id), $r->get('exercise', $x->exercise_id), $r->get('closing', $x->closing_snapshot_id),
            $r->get('expense', $x->expense_id), $r->get('expense_line', $x->expense_line_id),
            $r->get('expense_line', $x->original_expense_line_id), $this->timestamp($x->created_at), $this->str($x->reason),
            $this->bool($x->belongs_to_closed_exercise), $this->str($x->source_type), $r->get($x->source_type, $x->source_origin_id),
            $this->str($x->source_label), PortablePayload::json($this->portableJson($this->decode($x->owner_context, []), $r)),
            $x->supplier_context === null ? '' : PortablePayload::json($this->portableJson($this->decode($x->supplier_context, []), $r)),
        ], $data['late_corrections']));
        $sheets['_MP2_error_annotations'] = $sheet('_MP2_error_annotations', array_map(fn (object $x): array => [
            $r->get('annotation', $x->id), $r->get('exercise', $x->exercise_id), $r->get('closing', $x->closing_snapshot_id),
            $this->timestamp($x->created_at), $this->str($x->kind), $this->str($x->reason), PortablePayload::json([
                'recorded_facts' => (string) $x->recorded_facts_version,
                'believed_correct_facts' => (string) $x->believed_correct_facts_version,
                'affected_sources' => (string) $x->affected_sources_version,
            ]), PortablePayload::json([
                'recorded' => $this->portableJson($this->decode($x->recorded_facts, []), $r),
                'believed_correct' => $this->portableJson($this->decode($x->believed_correct_facts, []), $r),
            ]), PortablePayload::json($this->portableAffectedSources($this->decode($x->affected_sources, []), $r)),
        ], $data['historical_error_annotations']));
        $sheets['_MP2_attachments'] = $sheet('_MP2_attachments', array_map(fn (object $x): array => [
            $r->get('attachment', $x->id), ...$this->attachmentOwner($x, $r), $this->str($x->original_name),
            $this->str($x->media_type), (string) $x->size_bytes, $this->str($x->sha256), $x->detached_at === null ? 'active' : 'detached',
        ], $data['attachments']));

        return $sheets;
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $machine
     * @return array<string, array{columns: list<string>, rows: list<list<string>>}>
     */
    private function visibleSheets(Company $company, array $machine): array
    {
        $info = [
            ['Voce', 'Valore'],
            ['Azienda', $company->name],
            ['Timezone', $company->timezone],
            ['Valuta', 'EUR, valori al netto IVA'],
            ['Avvertenza', 'Backup consultabile. Eseguire analisi su una copia: qualunque modifica invalida il restore garantito.'],
        ];
        $views = [
            'Informazioni' => ['columns' => array_shift($info), 'rows' => $info],
            'Riepilogo Esercizi' => ['columns' => ['Anno', 'Stato'], 'rows' => array_map(fn (array $x): array => [$x[1], $x[2]], $machine['_MP2_exercises']['rows'])],
            'Budget' => ['columns' => ['Versione', 'Scopo', 'Approvato il', 'Totale'], 'rows' => array_map(fn (array $x): array => [$x[2], $x[3], $x[4], $x[6]], $machine['_MP2_budgets']['rows'])],
            'Spese' => ['columns' => ['Descrizione', 'Origine', 'Note', 'Stornata il'], 'rows' => array_map(fn (array $x): array => [$x[8], $x[6], $x[9], $x[10]], $machine['_MP2_expenses']['rows'])],
            'Progetti' => ['columns' => ['Titolo', 'Descrizione', 'Stato iniziale', 'Archiviato il'], 'rows' => array_map(fn (array $x): array => [$x[1], $x[2], $x[4], $x[6]], $machine['_MP2_projects']['rows'])],
            'Contratti' => ['columns' => ['Titolo', 'Inizio', 'Scadenza', 'Rinnovo automatico'], 'rows' => array_map(fn (array $x): array => [$x[2], $x[4], $x[5], $x[7]], $machine['_MP2_contracts']['rows'])],
            'Fornitori' => ['columns' => ['Ragione sociale', 'Partita IVA', 'Note', 'Archiviato il'], 'rows' => array_map(fn (array $x): array => [$x[1], $x[2], $x[3], $x[4]], $machine['_MP2_suppliers']['rows'])],
            'Centri di Costo' => ['columns' => ['Nome', 'Archiviato il'], 'rows' => array_map(fn (array $x): array => [$x[1], $x[2]], $machine['_MP2_cost_centers']['rows'])],
            'Chiusure' => ['columns' => ['Esercizio', 'Chiusa il', 'Allocato finale', 'Effettivo'], 'rows' => array_map(fn (array $x): array => [$x[3], $x[4], $x[7], $x[8]], $machine['_MP2_closings']['rows'])],
            'Correzioni' => ['columns' => ['Registrata il', 'Tipo', 'Motivo'], 'rows' => [
                ...array_map(fn (array $x): array => [$x[6], 'Correzione tardiva', $x[7]], $machine['_MP2_late_corrections']['rows']),
                ...array_map(fn (array $x): array => [$x[3], 'Annotazione: '.$x[4], $x[5]], $machine['_MP2_error_annotations']['rows']),
            ]],
            'Allegati' => ['columns' => ['Proprietario', 'Nome', 'Media type', 'Byte', 'SHA-256', 'Stato'], 'rows' => array_map(fn (array $x): array => [$x[1], $x[3], $x[4], $x[5], $x[6], $x[7]], $machine['_MP2_attachments']['rows'])],
        ];

        return $views;
    }

    /** @return array{0: string, 1: string} */
    private function attachmentOwner(object $attachment, PortableReferences $r): array
    {
        return match (true) {
            $attachment->contract_id !== null => ['contract', $r->get('contract', $attachment->contract_id)],
            $attachment->expense_id !== null => ['expense', $r->get('expense', $attachment->expense_id)],
            $attachment->expense_line_id !== null => ['expense_line', $r->get('expense_line', $attachment->expense_line_id)],
            $attachment->historical_error_annotation_id !== null => ['historical_error_annotation', $r->get('annotation', $attachment->historical_error_annotation_id)],
            default => throw new \UnexpectedValueException('Attachment has no portable owner.'),
        };
    }

    /** @param list<object> $attachments */
    private function evidenceAttachmentRef(mixed $id, array $attachments, PortableReferences $r): string
    {
        if ($id === null) {
            return '';
        }

        return collect($attachments)->contains(fn (object $x): bool => (int) $x->id === (int) $id)
            ? $r->get('attachment', $id)
            : '';
    }

    /** @param array<string, mixed> $effects
     * @return array<string, mixed>
     */
    private function portableEffects(array $effects, PortableReferences $r): array
    {
        $sourceLines = [];
        foreach ($this->objectList($effects['source_lines'] ?? []) as $x) {
            $sourceLines[] = [
                'expense_ref' => $r->get('expense', $x['expense_id'] ?? null),
                'line_ref' => $r->get('expense_line', $x['expense_line_id'] ?? null),
                'expense_reversed_after' => (bool) ($x['expense_reversed_after'] ?? false),
                'amount_before' => $this->str($x['amount_before'] ?? null),
                'amount_after' => $this->str($x['amount_after'] ?? null),
                'quantity' => $this->str($x['quantity'] ?? null),
                'unit_amount' => $this->str($x['unit_amount'] ?? null),
                'unit_of_measure' => $this->str($x['unit_of_measure'] ?? null),
                'note' => $this->str($x['note'] ?? null),
                'annulled_before' => (bool) ($x['annulled_before'] ?? false),
                'annulled_after' => (bool) ($x['annulled_after'] ?? false),
            ];
        }
        $destinationExpenses = [];
        foreach ($this->objectList($effects['destination_expenses'] ?? []) as $x) {
            $estimateLines = [];
            foreach ($this->objectList($x['estimate_lines'] ?? []) as $line) {
                $estimateLines[] = [
                    'line_ref' => $r->get('expense_line', $line['expense_line_id'] ?? null),
                    'amount' => $this->str($line['amount'] ?? null), 'quantity' => $this->str($line['quantity'] ?? null),
                    'unit_amount' => $this->str($line['unit_amount'] ?? null), 'unit_of_measure' => $this->str($line['unit_of_measure'] ?? null),
                    'note' => $this->str($line['note'] ?? null), 'annulled' => (bool) ($line['annulled'] ?? false),
                ];
            }
            $destinationExpenses[] = [
                'expense_ref' => $r->get('expense', $x['expense_id'] ?? null),
                'copied_from_expense_ref' => $r->origin('expense', $x['copied_from_origin_key'] ?? null),
                'reversed' => (bool) ($x['reversed'] ?? false),
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
            throw new \UnexpectedValueException('Expected a list of effect objects.');
        }
        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new \UnexpectedValueException('Expected a list of effect objects.');
            }
        }

        return $value;
    }

    /** @param array<int, mixed> $sources
     * @return list<array{type: string, ref: string, label: string}>
     */
    private function portableAffectedSources(array $sources, PortableReferences $r): array
    {
        $types = ['closing_snapshot' => 'closing', 'cost_center' => 'cost_center'];

        return collect($sources)->map(fn (array $source): array => [
            'type' => (string) $source['type'],
            'ref' => $r->get($types[$source['type']] ?? $source['type'], $source['id']),
            'label' => (string) $source['label'],
        ])->values()->all();
    }

    private function portableJson(mixed $value, PortableReferences $r, ?string $parent = null): mixed
    {
        if (! is_array($value)) {
            if (is_string($value) && preg_match('/^(expense|project|contract):(\d+)$/', $value)) {
                return $r->origin('expense', $value);
            }

            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->portableJson($item, $r, $parent), $value);
        }
        $forbidden = ['proposal_id', 'proposal_item_id', 'proposal_action_id', 'operation_id', 'reprogramming_operation_id', 'approval_event_sequences', 'event_references', 'approved_actions'];
        $keyTypes = [
            'expense_id' => ['expense_ref', 'expense'], 'line_id' => ['line_ref', 'expense_line'],
            'expense_line_id' => ['line_ref', 'expense_line'], 'project_id' => ['project_ref', 'project'],
            'contract_id' => ['contract_ref', 'contract'], 'supplier_id' => ['supplier_ref', 'supplier'],
            'cost_center_id' => ['cost_center_ref', 'cost_center'], 'exercise_id' => ['exercise_ref', 'exercise'],
            'condition_id' => ['condition_ref', 'contract_condition'], 'renewal_configuration_id' => ['renewal_ref', 'contract_renewal'],
        ];
        $result = [];
        foreach ($value as $key => $item) {
            if (in_array($key, $forbidden, true) || str_ends_with((string) $key, '_revision')) {
                continue;
            }
            if ($key === 'origin_id') {
                $type = (string) ($value['source_type'] ?? '');
                if (isset(BusinessBackupContract::PREFIXES[$type])) {
                    $result['source_ref'] = $r->get($type, $item);
                }

                continue;
            }
            if ($key === 'origin_key' || $key === 'copied_from_origin_key' || $key === 'source_expense_origin_key') {
                $result[str_replace('origin_key', 'source_ref', $key)] = $item === null ? '' : $r->origin('expense', (string) $item);

                continue;
            }
            if ($key === 'id' && in_array($parent, ['supplier', 'cost_center'], true)) {
                $result['ref'] = $r->get($parent, $item);

                continue;
            }
            if (isset($keyTypes[$key])) {
                [$portableKey, $type] = $keyTypes[$key];
                $result[$portableKey] = $r->get($type, $item);

                continue;
            }
            $result[$key] = $this->portableJson($item, $r, (string) $key);
        }

        return $result;
    }

    private function decode(mixed $value, mixed $default): mixed
    {
        if (is_string($value)) {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return $value ?? $default;
    }

    private function str(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function bool(mixed $value): string
    {
        return (bool) $value ? '1' : '0';
    }

    private function date(mixed $value): string
    {
        return $value === null ? '' : CarbonImmutable::parse((string) $value)->toDateString();
    }

    private function timestamp(mixed $value): string
    {
        return $value === null ? '' : CarbonImmutable::parse((string) $value, 'UTC')->toIso8601String();
    }
}
