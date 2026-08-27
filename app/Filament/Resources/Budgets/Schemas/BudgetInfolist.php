<?php

namespace App\Filament\Resources\Budgets\Schemas;

use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractState;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectState;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class BudgetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.budgets.components.overview')
                ->viewData(fn (BudgetSnapshot $record): array => ['overview' => self::overview($record)])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function overview(BudgetSnapshot $budget): array
    {
        $budget->loadMissing(['company', 'exercise', 'approver', 'proposal', 'previousBudget', 'rows', 'evidence']);

        $rows = $budget->rows
            ->sortBy('id')
            ->map(fn (BudgetSourceRow $row): array => self::source($row))
            ->values()
            ->all();

        $affectedExercisesValue = $budget->getAttribute('affected_exercises');
        $affectedExercises = collect(is_array($affectedExercisesValue) ? $affectedExercisesValue : [])
            ->map(fn (mixed $impact): mixed => is_array($impact) ? ($impact['year'] ?? null) : $impact)
            ->filter(fn (mixed $year): bool => is_int($year) || is_string($year))
            ->map(fn (int|string $year): string => (string) $year)
            ->unique()
            ->values()
            ->all();

        $evidence = $budget->evidence
            ->filter(fn (BudgetEvidence $item): bool => self::hasEvidence($item))
            ->map(fn (BudgetEvidence $item): array => [
                'subject' => $item->external_subject,
                'venue' => $item->external_venue,
                'reason' => $item->reason,
                'attachment' => $item->original_name,
                'media_type' => $item->media_type,
                'size' => self::fileSize($item->size_bytes),
                'sha256' => $item->sha256,
                'download_url' => $item->storage_path === null ? null : route('budget-evidence.download', $item),
            ])
            ->values()
            ->all();

        return [
            'budget' => [
                'company' => $budget->company->name,
                'exercise' => $budget->exercise->year,
                'version' => 'v'.$budget->version,
                'previous_version' => $budget->previousBudget === null ? '—' : 'v'.$budget->previousBudget->version,
                'purpose' => $budget->purpose->label(),
                'approved_at' => CarbonImmutable::parse($budget->approved_at)->timezone($budget->company->timezone)->format('d/m/Y H:i'),
                'approver' => $budget->approver->name,
                'proposal' => '#'.$budget->proposal_id,
                'total' => self::money($budget->total_approved_allocation),
                'affected_exercises' => $affectedExercises,
                'source_count' => count($rows),
            ],
            'source_counts' => collect($rows)
                ->countBy('type_value')
                ->mapWithKeys(fn (int $count, string $type): array => [
                    $type => [
                        'label' => ProposalSourceType::from($type)->label(),
                        'count' => $count,
                    ],
                ])
                ->all(),
            'sources' => $rows,
            'evidence' => $evidence,
        ];
    }

    /** @return array<string, mixed> */
    private static function source(BudgetSourceRow $row): array
    {
        $detail = $row->detail;
        $typeDetail = is_array($detail[$row->source_type->value] ?? null)
            ? $detail[$row->source_type->value]
            : [];
        $actions = $detail['approved_actions'] ?? [];
        $relations = $detail['relations'] ?? [];
        $eventSequences = $detail['approval_event_sequences'] ?? [];

        return [
            'type_value' => $row->source_type->value,
            'type_label' => $row->source_type->label(),
            'icon' => match ($row->source_type) {
                ProposalSourceType::Expense => 'heroicon-o-receipt-percent',
                ProposalSourceType::Project => 'heroicon-o-briefcase',
                ProposalSourceType::Contract => 'heroicon-o-document-text',
            },
            'label' => $row->label,
            'summary' => $row->summary,
            'origin_key' => $row->origin_key,
            'proposal_item_id' => $row->proposal_item_id,
            'copied_from_origin_key' => $row->copied_from_origin_key,
            'supplier' => $row->supplier_label,
            'cost_center' => $row->cost_center_label,
            'estimates' => self::money($row->approved_estimates),
            'carryover' => self::money($row->approved_carryover),
            'carryover_state' => match ($row->carryover_state) {
                'provisional' => 'Provvisorio',
                'consolidated' => 'Consolidato',
                default => '—',
            },
            'allocation' => self::money($row->approved_allocation),
            'start_state' => self::stateLabel($row->source_type, $row->start_state),
            'end_state' => self::stateLabel($row->source_type, $row->end_state),
            'detail_version' => $row->detail_version,
            'expense' => $row->source_type === ProposalSourceType::Expense ? self::expenseDetail($typeDetail) : null,
            'project' => $row->source_type === ProposalSourceType::Project ? self::projectDetail($typeDetail) : null,
            'contract' => $row->source_type === ProposalSourceType::Contract ? self::contractDetail($typeDetail) : null,
            'actions' => collect(is_array($actions) ? $actions : [])->map(fn (mixed $action): array => self::action(is_array($action) ? $action : []))->all(),
            'relations' => collect(is_array($relations) ? $relations : [])->map(fn (mixed $relation): array => self::relation(is_array($relation) ? $relation : []))->all(),
            'event_sequences' => collect(is_array($eventSequences) ? $eventSequences : [])->map(fn (mixed $sequence): string => '#'.(string) $sequence)->all(),
            'schema_version' => $detail['schema_version'] ?? null,
        ];
    }

    /** @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function expenseDetail(array $detail): array
    {
        $owner = is_array($detail['owner'] ?? null) ? $detail['owner'] : [];
        $lines = is_array($detail['active_estimate_lines'] ?? null) ? $detail['active_estimate_lines'] : [];

        return [
            'origin' => match ($detail['origin'] ?? null) {
                'manual' => 'Manuale',
                'system' => 'Sistema',
                default => '—',
            },
            'owner' => $owner['label'] ?? '—',
            'state' => self::stateLabel(ProposalSourceType::Expense, $detail['state'] ?? null),
            'lines' => collect($lines)->map(fn (mixed $line): array => self::estimateLine(is_array($line) ? $line : []))->all(),
        ];
    }

    /** @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function projectDetail(array $detail): array
    {
        $expenses = is_array($detail['expenses'] ?? null) ? $detail['expenses'] : [];
        $transitions = is_array($detail['approved_transitions'] ?? null) ? $detail['approved_transitions'] : [];

        return [
            'deferral_mode' => ProjectDeferralMode::tryFrom((string) ($detail['deferral_mode'] ?? ''))?->label() ?? '—',
            'reprogrammed' => self::money($detail['approved_reprogrammed_amount'] ?? '0.00'),
            'transitions' => collect($transitions)->map(fn (mixed $transition): array => self::transition(is_array($transition) ? $transition : []))->all(),
            'expenses' => collect($expenses)->map(function (mixed $expense): array {
                $expense = is_array($expense) ? $expense : [];
                $supplier = is_array($expense['supplier'] ?? null) ? $expense['supplier'] : [];
                $lines = is_array($expense['active_estimate_lines'] ?? null) ? $expense['active_estimate_lines'] : [];

                return [
                    'description' => $expense['description'] ?? '—',
                    'supplier' => $supplier['label'] ?? '—',
                    'total' => self::money($expense['approved_estimate_total'] ?? '0.00'),
                    'lines' => collect($lines)->map(fn (mixed $line): array => self::estimateLine(is_array($line) ? $line : []))->all(),
                ];
            })->all(),
        ];
    }

    /** @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function contractDetail(array $detail): array
    {
        $conditions = is_array($detail['conditions'] ?? null) ? $detail['conditions'] : [];

        return [
            'start_date' => self::date($detail['contractual_start_date'] ?? null),
            'expiry_date' => self::date($detail['next_expiry_date'] ?? null),
            'automatic_renewal' => ($detail['automatic_renewal'] ?? false) ? 'Sì' : 'No',
            'renewal_duration' => isset($detail['renewal_duration_months']) ? $detail['renewal_duration_months'].' mesi' : '—',
            'notice' => isset($detail['notice_days']) ? $detail['notice_days'].' giorni' : '—',
            'cancellation_deadline' => self::date($detail['cancellation_deadline'] ?? null),
            'conditions' => collect($conditions)->map(function (mixed $condition): array {
                $condition = is_array($condition) ? $condition : [];
                $composition = is_array($condition['annual_composition'] ?? null) ? $condition['annual_composition'] : [];

                return [
                    'amount' => self::money($condition['amount'] ?? '0.00'),
                    'cycle' => ContractCycleType::tryFrom((string) ($condition['cycle'] ?? ''))?->label() ?? '—',
                    'attribution' => ContractAttributionMode::tryFrom((string) ($condition['attribution_mode'] ?? ''))?->label() ?? '—',
                    'valid_from' => self::date($condition['valid_from'] ?? null),
                    'valid_to' => self::date($condition['valid_to'] ?? null, 'Senza termine'),
                    'state' => ($condition['annulled'] ?? false) ? 'Annullata' : 'Attiva',
                    'reason' => $condition['reason'] ?? null,
                    'composition' => collect($composition)->map(fn (mixed $item): array => self::compositionItem(is_array($item) ? $item : []))->all(),
                ];
            })->all(),
        ];
    }

    /** @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function estimateLine(array $line): array
    {
        return [
            'amount' => self::money($line['amount'] ?? '0.00'),
            'quantity' => $line['quantity'] ?? '—',
            'unit_amount' => isset($line['unit_amount']) ? self::money($line['unit_amount']) : '—',
            'unit_of_measure' => $line['unit_of_measure'] ?? '—',
            'note' => $line['note'] ?? '—',
        ];
    }

    /** @param array<string, mixed> $transition
     * @return array<string, mixed>
     */
    private static function transition(array $transition): array
    {
        return [
            'from' => self::projectState($transition['from_state'] ?? null),
            'to' => self::projectState($transition['to_state'] ?? null),
            'date' => self::date($transition['effective_date'] ?? null),
            'reason' => $transition['reason'] ?? null,
        ];
    }

    /** @param array<string, mixed> $item
     * @return array<string, string>
     */
    private static function compositionItem(array $item): array
    {
        return [
            'cycle_start' => self::date($item['cycle_start'] ?? null),
            'attribution_date' => self::date($item['attribution_date'] ?? null),
            'amount' => self::money($item['amount'] ?? '0.00'),
        ];
    }

    /** @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private static function action(array $action): array
    {
        $type = (string) ($action['type'] ?? '');

        return [
            'sequence' => $action['sequence'] ?? null,
            'label' => self::actionLabel($type),
            'reason' => $action['reason'] ?? null,
            'payload' => self::json($action['payload'] ?? []),
        ];
    }

    /** @param array<string, mixed> $relation
     * @return array<string, mixed>
     */
    private static function relation(array $relation): array
    {
        $project = is_array($relation['project'] ?? null) ? $relation['project'] : [];
        $contract = is_array($relation['contract'] ?? null) ? $relation['contract'] : [];

        return [
            'project' => $project['label'] ?? '—',
            'project_key' => $project['origin_key'] ?? null,
            'contract' => $contract['label'] ?? '—',
            'contract_key' => $contract['origin_key'] ?? null,
            'note' => $relation['note'] ?? null,
        ];
    }

    private static function stateLabel(ProposalSourceType $type, mixed $state): string
    {
        if ($state === null || $state === 'absent') {
            return 'Assente alla data';
        }

        return match ($type) {
            ProposalSourceType::Expense => match ($state) {
                'active' => 'Attiva',
                'reversed' => 'Stornata',
                default => (string) $state,
            },
            ProposalSourceType::Project => self::projectState($state),
            ProposalSourceType::Contract => ContractState::tryFrom((string) $state)?->label() ?? (string) $state,
        };
    }

    private static function projectState(mixed $state): string
    {
        if ($state === null || $state === 'absent') {
            return 'Assente alla data';
        }

        return ProjectState::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function actionLabel(string $type): string
    {
        return match (ProposalActionType::tryFrom($type)) {
            ProposalActionType::CreateExpense => 'Creazione Spesa',
            ProposalActionType::CopyExpense => 'Copia Spesa',
            ProposalActionType::SetExpenseEstimates => 'Aggiornamento Stime della Spesa',
            ProposalActionType::SetExpenseOwner => 'Cambio contenitore della Spesa',
            ProposalActionType::SetExpenseSupplier => 'Cambio Fornitore della Spesa',
            ProposalActionType::SetExpenseCostCenter => 'Cambio Centro di Costo della Spesa',
            ProposalActionType::ReverseExpense => 'Storno della Spesa',
            ProposalActionType::RestoreExpense => 'Ripristino della Spesa',
            ProposalActionType::CreateProject => 'Creazione Progetto',
            ProposalActionType::PlanProjectChildExpenses => 'Pianificazione Spese del Progetto',
            ProposalActionType::SetProjectCostCenter => 'Cambio Centro di Costo del Progetto',
            ProposalActionType::PlanProjectTransition => 'Transizione del Progetto',
            ProposalActionType::PlanProjectDeferral => 'Rinvio del Progetto',
            ProposalActionType::CreateProjectAllocation => 'Nuova allocazione del Progetto',
            ProposalActionType::CreateContract => 'Creazione Contratto',
            ProposalActionType::AddContractCondition => 'Nuova condizione del Contratto',
            ProposalActionType::ChangeContractEconomics => 'Modifica economica del Contratto',
            ProposalActionType::PlanContractLifecycle => 'Ciclo di vita del Contratto',
            ProposalActionType::SetContractRenewal => 'Rinnovo del Contratto',
            ProposalActionType::SetContractCostCenter => 'Cambio Centro di Costo del Contratto',
            ProposalActionType::LinkProjectContract => 'Relazione Progetto–Contratto',
            default => $type,
        };
    }

    private static function date(mixed $date, string $empty = '—'): string
    {
        if (! is_string($date) || $date === '') {
            return $empty;
        }

        return (new \DateTimeImmutable($date))->format('d/m/Y');
    }

    private static function money(mixed $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }

    private static function fileSize(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        return $bytes < 1024
            ? $bytes.' B'
            : number_format($bytes / 1024, 1, ',', '.').' KB';
    }

    private static function hasEvidence(BudgetEvidence $evidence): bool
    {
        return filled($evidence->external_subject)
            || filled($evidence->external_venue)
            || filled($evidence->reason)
            || filled($evidence->original_name);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
