<?php

namespace App\Filament\Resources\Proposals\Schemas;

use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectState;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalPlanData;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * @phpstan-type ReferenceMaps array{
 *     exercises: Collection<int, string>,
 *     cost_centers: Collection<int, string>,
 *     projects: Collection<int, string>,
 *     contracts: Collection<int, string>,
 *     proposed_projects: Collection<string, string>
 * }
 */
class ProposalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.proposals.components.overview')
                ->viewData(fn (Proposal $record): array => ['overview' => self::overview($record)])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function overview(Proposal $proposal): array
    {
        $proposal->loadMissing([
            'company.exercises',
            'exercise',
            'referenceBudget',
            'creator',
            'approver',
            'discarder',
            'budget',
            'items.actionHistory.creator',
            'items.actionHistory.withdrawer',
            'items.expense',
            'items.project',
            'items.contract',
        ]);

        $review = app(ProposalReadiness::class)->assessProposal($proposal);
        $mainImpact = collect($review['impacts'])->firstWhere('exercise_id', $proposal->exercise_id) ?? [
            'allocation_before' => '0.00',
            'allocation_after' => '0.00',
            'allocation_delta' => '0.00',
            'sources' => [],
        ];

        $maps = self::referenceMaps($proposal);
        $impactByItem = collect(ProposalPlanData::rows($mainImpact['sources'] ?? null, 'sources'))
            ->keyBy('proposal_item_id');
        $items = $proposal->items
            ->sortBy('id')
            ->map(fn (ProposalItem $item): array => self::item(
                $item,
                $impactByItem->get($item->proposal_item_id, []),
                $maps,
            ))
            ->values()
            ->all();

        return [
            'proposal' => [
                'exercise' => $proposal->exercise->year,
                'purpose' => $proposal->purpose->label(),
                'status' => $proposal->status->label(),
                'status_value' => $proposal->status->value,
                'reference_budget' => $proposal->referenceBudget === null ? '—' : 'v'.$proposal->referenceBudget->version,
                'created_by' => $proposal->creator->name,
                'created_at' => self::dateTime($proposal->created_at, $proposal->company->timezone),
                'terminal_by' => match ($proposal->status->value) {
                    'approved' => $proposal->approver->name,
                    'discarded' => $proposal->discarder->name,
                    default => null,
                },
                'terminal_at' => self::dateTime($proposal->approved_at ?? $proposal->discarded_at, $proposal->company->timezone),
                'budget' => $proposal->budget === null ? null : 'v'.$proposal->budget->version,
                'item_count' => count($items),
                'actual' => self::money(Decimal::sum(collect($items)->pluck('actual_raw'))),
                'allocation_before' => self::money($mainImpact['allocation_before'] ?? '0.00'),
                'allocation_after' => self::money($mainImpact['allocation_after'] ?? '0.00'),
                'allocation_delta' => self::signedMoney($mainImpact['allocation_delta'] ?? '0.00'),
                'allocation_delta_tone' => self::deltaTone($mainImpact['allocation_delta'] ?? '0.00'),
                'context' => $proposal->purpose->value === 'revision'
                    ? 'La realtà corrente è la base; il Budget approvato resta un confronto immutabile.'
                    : 'La realtà corrente è la base della proposta iniziale.',
            ],
            'verification' => self::verification($proposal, $review),
            'source_counts' => collect($items)
                ->countBy('type_value')
                ->mapWithKeys(fn (int $count, string $type): array => [
                    $type => ['label' => ProposalSourceType::from($type)->label(), 'count' => $count],
                ])->all(),
            'items' => $items,
            'impacts' => collect($review['impacts'])->map(
                fn (array $impact): array => self::impact($impact, collect($items)->keyBy('proposal_item_id')),
            )->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $impact
     * @param  ReferenceMaps  $maps
     * @return array<string, mixed>
     */
    private static function item(ProposalItem $item, array $impact, array $maps): array
    {
        $baseline = (array) data_get($item->baseline, 'plan_baseline', []);
        $result = $item->result;
        $actual = self::actualTotal((array) data_get($item->baseline, 'actual_context', []));
        $label = self::sourceLabel($item->source_type, $result, $baseline);

        return [
            'type_value' => $item->source_type->value,
            'type_label' => $item->source_type->label(),
            'icon' => self::sourceIcon($item->source_type),
            'label' => $label,
            'supplier' => filled($result['supplier_label'] ?? null) ? (string) $result['supplier_label'] : '—',
            'cost_center' => self::costCenter($result, $maps['cost_centers']),
            'origin_key' => $result['origin_key'] ?? $baseline['origin_key'] ?? 'Nuova sorgente',
            'proposal_item_id' => $item->proposal_item_id,
            'copied_from_origin_key' => $item->copied_from_origin_key,
            'baseline_revision' => $item->baseline_revision,
            'last_aligned_at' => self::dateTime($item->last_aligned_at, $item->proposal->company->timezone),
            'readiness' => $item->readiness_state->label(),
            'readiness_value' => $item->readiness_state->value,
            'readiness_reasons' => collect(ProposalPlanData::rows($item->readiness_reasons, 'readiness_reasons'))
                ->pluck('message')->filter()->values()->all(),
            'archive' => $item->read_only_source ? 'Archiviata · sola lettura' : 'Operativa',
            'actual_raw' => $actual,
            'actual' => self::money($actual),
            'has_actuals' => (bool) data_get($item->baseline, 'actual_context.has_actuals', false),
            'allocation_before' => self::money($impact['before'] ?? '0.00'),
            'allocation_after' => self::money($impact['after'] ?? '0.00'),
            'allocation_delta' => self::signedMoney($impact['delta'] ?? '0.00'),
            'allocation_delta_tone' => self::deltaTone($impact['delta'] ?? '0.00'),
            'state_before' => self::stateLabel($item->source_type, $impact['state_before'] ?? null),
            'state_after' => self::stateLabel($item->source_type, $impact['state_after'] ?? null),
            'details' => match ($item->source_type) {
                ProposalSourceType::Expense => self::expenseDetails($result, $maps),
                ProposalSourceType::Project => self::projectDetails($result, $maps),
                ProposalSourceType::Contract => self::contractDetails($result, $maps),
            },
            'actions' => $item->actionHistory->map(fn (ProposalAction $action): array => self::action($action))->all(),
        ];
    }

    /** @param array<string, mixed> $result
     * @param  ReferenceMaps  $maps
     * @return array<string, mixed>
     */
    private static function expenseDetails(array $result, array $maps): array
    {
        return [
            'kind' => 'expense',
            'description' => $result['description'] ?? '—',
            'notes' => $result['notes'] ?? null,
            'exercise' => $maps['exercises']->get((int) ($result['exercise_id'] ?? 0), '—'),
            'owner' => self::expenseOwner($result, $maps),
            'state' => ($result['reversed'] ?? filled($result['reversed_at'] ?? null)) ? 'Stornata' : 'Attiva',
            'lines' => self::estimateLines($result['estimate_lines'] ?? []),
        ];
    }

    /** @param array<string, mixed> $result
     * @param  ReferenceMaps  $maps
     * @return array<string, mixed>
     */
    private static function projectDetails(array $result, array $maps): array
    {
        $deferral = is_array($result['incoming_deferral'] ?? null) ? $result['incoming_deferral'] : [];
        $transitions = collect(ProposalPlanData::rows($result['transitions'] ?? null, 'transitions'))
            ->map(fn (array $transition): array => self::projectTransition($transition, false))
            ->concat(collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))
                ->map(fn (array $transition): array => self::projectTransition($transition, true)))
            ->values()->all();

        return [
            'kind' => 'project',
            'description' => $result['description'] ?? null,
            'notes' => $result['notes'] ?? null,
            'initial_state' => ProjectState::tryFrom((string) ($result['initial_state'] ?? ''))?->label() ?? '—',
            'initial_date' => self::date($result['initial_effective_date'] ?? null),
            'deferral_mode' => ProjectDeferralMode::tryFrom((string) ($deferral['mode'] ?? 'none'))?->label() ?? 'Nessuna',
            'carryover' => self::money($deferral['carryover_amount'] ?? '0.00'),
            'reprogrammed' => self::money($deferral['reprogrammed_amount'] ?? '0.00'),
            'transitions' => $transitions,
            'expenses' => self::expensePlans($result['expense_plan'] ?? [], $maps),
        ];
    }

    /** @param array<string, mixed> $result
     * @param  ReferenceMaps  $maps
     * @return array<string, mixed>
     */
    private static function contractDetails(array $result, array $maps): array
    {
        $conditions = collect(ProposalPlanData::rows($result['conditions'] ?? null, 'conditions'))
            ->map(fn (array $condition): array => self::condition($condition, 'Esistente'))
            ->concat(collect(ProposalPlanData::rows($result['planned_conditions'] ?? null, 'planned_conditions'))
                ->map(fn (array $condition): array => self::condition($condition, 'Pianificata')))
            ->values()->all();
        $conditionChanges = collect(ProposalPlanData::rows($result['planned_condition_changes'] ?? null, 'planned_condition_changes'))
            ->map(fn (array $change): array => [
                'condition' => '#'.($change['condition_id'] ?? '—'),
                'amount' => self::money($change['amount'] ?? '0.00'),
                'cycle' => self::cycle($change['cycle'] ?? null),
                'attribution' => self::attribution($change['attribution_mode'] ?? null),
                'effective_date' => self::date($change['effective_date'] ?? null),
                'reason' => $change['delay_reason'] ?? null,
            ])->all();
        $lifecycle = collect(ProposalPlanData::rows($result['lifecycle_facts'] ?? null, 'lifecycle_facts'))
            ->map(fn (array $fact): array => self::lifecycleFact($fact, false))
            ->concat(collect(ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))
                ->map(fn (array $fact): array => self::lifecycleFact($fact, true)))
            ->values()->all();

        return [
            'kind' => 'contract',
            'notes' => $result['notes'] ?? null,
            'start_date' => self::date($result['contractual_start_date'] ?? null),
            'expiry_date' => self::date($result['next_expiry_date'] ?? null, 'Senza scadenza definita'),
            'automatic_renewal' => ($result['automatic_renewal'] ?? false) ? 'Sì' : 'No',
            'renewal_duration' => isset($result['renewal_duration_months']) ? $result['renewal_duration_months'].' mesi' : '—',
            'notice' => isset($result['notice_days']) ? $result['notice_days'].' giorni' : '—',
            'conditions' => $conditions,
            'condition_changes' => $conditionChanges,
            'lifecycle' => $lifecycle,
            'expenses' => self::expensePlans($result['expense_plan'] ?? [], $maps),
        ];
    }

    /**
     * @param  ReferenceMaps  $maps
     * @return list<array<string, mixed>>
     */
    private static function expensePlans(mixed $plans, array $maps): array
    {
        return collect(ProposalPlanData::rows($plans, 'expense_plan'))->map(fn (array $expense): array => [
            'description' => $expense['description'] ?? 'Spesa',
            'supplier' => $expense['supplier_label'] ?? '—',
            'exercise' => $maps['exercises']->get((int) ($expense['exercise_id'] ?? 0), '—'),
            'total' => self::money(self::estimateTotal($expense['estimate_lines'] ?? [])),
            'lines' => self::estimateLines($expense['estimate_lines'] ?? []),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private static function estimateLines(mixed $lines): array
    {
        return collect(ProposalPlanData::rows($lines, 'estimate_lines'))
            ->filter(fn (array $line): bool => ! ($line['annulled'] ?? false) && ! filled($line['annulled_at'] ?? null))
            ->map(fn (array $line): array => [
                'amount' => self::money($line['amount'] ?? '0.00'),
                'quantity' => self::decimalValue($line['quantity'] ?? null),
                'unit_amount' => isset($line['unit_amount']) ? self::money($line['unit_amount']) : '—',
                'unit_of_measure' => $line['unit_of_measure'] ?? '—',
                'note' => $line['note'] ?? '—',
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private static function condition(array $condition, string $origin): array
    {
        return [
            'origin' => $origin,
            'amount' => self::money($condition['amount'] ?? '0.00'),
            'cycle' => self::cycle($condition['cycle'] ?? null),
            'attribution' => self::attribution($condition['attribution_mode'] ?? null),
            'valid_from' => self::date($condition['valid_from'] ?? null),
            'valid_to' => self::date($condition['valid_to'] ?? null, 'Senza termine'),
            'status' => filled($condition['annulled_at'] ?? null) ? 'Annullata' : 'Attiva',
            'reason' => $condition['reason'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $transition
     * @return array<string, mixed>
     */
    private static function projectTransition(array $transition, bool $planned): array
    {
        return [
            'from' => self::projectState($transition['from_state'] ?? null),
            'to' => self::projectState($transition['to_state'] ?? null),
            'date' => self::date($transition['effective_date'] ?? null),
            'status' => filled($transition['annulled_at'] ?? null) ? 'Annullata' : ($planned ? 'Pianificata' : 'Esistente'),
            'reason' => $transition['reason'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    private static function lifecycleFact(array $fact, bool $planned): array
    {
        return [
            'type' => self::lifecycleLabel($fact['type'] ?? null),
            'declared_date' => self::date($fact['declared_contractual_date'] ?? null),
            'effective_date' => self::date($fact['effective_date'] ?? $fact['state_change_date'] ?? null),
            'status' => filled($fact['annulled_at'] ?? null) ? 'Annullato' : ($planned ? 'Pianificato' : 'Registrato'),
            'reason' => $fact['reason'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function action(ProposalAction $action): array
    {
        return [
            'sequence' => $action->sequence,
            'label' => self::actionLabel($action->action_type),
            'status' => $action->status->label(),
            'reason' => $action->reason,
            'created_by' => $action->creator->name,
            'withdrawn_by' => $action->withdrawer?->name,
            'withdrawn_at' => self::dateTime($action->withdrawn_at, $action->proposal->company->timezone),
            'withdraw_reason' => $action->withdraw_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $impact
     * @param  Collection<string, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function impact(array $impact, Collection $items): array
    {
        $sources = collect(ProposalPlanData::rows($impact['sources'] ?? null, 'sources'))->map(function (array $source) use ($items): array {
            $item = $items->get((string) ($source['proposal_item_id'] ?? ''));

            return [
                'label' => $item['label'] ?? ($source['origin_key'] ?? 'Sorgente'),
                'type' => isset($source['source_type']) ? ProposalSourceType::from((string) $source['source_type'])->label() : '—',
                'before' => self::money($source['before'] ?? '0.00'),
                'after' => self::money($source['after'] ?? '0.00'),
                'delta' => self::signedMoney($source['delta'] ?? '0.00'),
                'state_before' => isset($source['source_type']) ? self::stateLabel(ProposalSourceType::from((string) $source['source_type']), $source['state_before'] ?? null) : '—',
                'state_after' => isset($source['source_type']) ? self::stateLabel(ProposalSourceType::from((string) $source['source_type']), $source['state_after'] ?? null) : '—',
            ];
        })->all();

        return [
            'year' => $impact['year'] ?? '—',
            'application' => ($impact['will_apply'] ?? false) ? 'Verrà applicato' : 'Storico invariato',
            'before' => self::money($impact['allocation_before'] ?? '0.00'),
            'after' => self::money($impact['allocation_after'] ?? '0.00'),
            'delta' => self::signedMoney($impact['allocation_delta'] ?? '0.00'),
            'delta_tone' => self::deltaTone($impact['allocation_delta'] ?? '0.00'),
            'sources' => $sources,
            'warnings' => array_values($impact['warnings'] ?? []),
            'blocks' => array_values($impact['blocks'] ?? []),
            'unchanged_budgets' => collect(ProposalPlanData::rows($impact['unchanged_budgets'] ?? null, 'unchanged_budgets'))
                ->map(fn (array $budget): string => 'Budget v'.$budget['version'])->all(),
            'stale_proposals' => collect(ProposalPlanData::rows($impact['stale_proposals'] ?? null, 'stale_proposals'))
                ->map(fn (array $stale): string => 'Proposta #'.$stale['proposal_id'])->unique()->values()->all(),
        ];
    }

    /** @param array<string, mixed> $review
     * @return array{ready: bool, tone: string, icon: string, label: string, message: string, blocks: list<string>}
     */
    private static function verification(Proposal $proposal, array $review): array
    {
        if ($proposal->status->value === 'approved') {
            return [
                'ready' => true,
                'tone' => 'ready',
                'icon' => 'heroicon-o-check-circle',
                'label' => $proposal->budget === null ? 'Proposta approvata' : 'Approvata e materializzata nel Budget v'.$proposal->budget->version,
                'message' => 'Le verifiche sono state superate al momento dell’approvazione.',
                'blocks' => [],
            ];
        }

        if ($proposal->status->value === 'discarded') {
            return [
                'ready' => false,
                'tone' => 'neutral',
                'icon' => 'heroicon-o-archive-box',
                'label' => 'Proposta scartata',
                'message' => 'La Proposta è conservata in sola lettura con il proprio storico.',
                'blocks' => [],
            ];
        }

        return [
            'ready' => (bool) $review['ready'],
            'tone' => $review['ready'] ? 'ready' : 'attention',
            'icon' => $review['ready'] ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle',
            'label' => $review['ready'] ? 'Pronta per l’approvazione' : 'Richiede attenzione',
            'message' => $review['ready'] ? 'Tutte le sorgenti risultano allineate e non sono presenti blocchi.' : '',
            'blocks' => collect(ProposalPlanData::rows($review['blocks'] ?? null, 'blocks'))
                ->map(fn (array $block): string => (string) $block['message'])->all(),
        ];
    }

    /** @return ReferenceMaps */
    private static function referenceMaps(Proposal $proposal): array
    {
        return [
            'exercises' => $proposal->company->exercises
                ->mapWithKeys(fn (Exercise $exercise): array => [(int) $exercise->id => self::exerciseLabel($exercise)]),
            'cost_centers' => CostCenter::query()->where('company_id', $proposal->company_id)->get(['id', 'name'])
                ->mapWithKeys(fn (CostCenter $costCenter): array => [(int) $costCenter->id => (string) $costCenter->name]),
            'projects' => Project::query()->where('company_id', $proposal->company_id)->get(['id', 'title'])
                ->mapWithKeys(fn (Project $project): array => [(int) $project->id => (string) $project->title]),
            'contracts' => Contract::query()->where('company_id', $proposal->company_id)->get(['id', 'title'])
                ->mapWithKeys(fn (Contract $contract): array => [(int) $contract->id => (string) $contract->title]),
            'proposed_projects' => $proposal->items->where('source_type', ProposalSourceType::Project)
                ->mapWithKeys(fn (ProposalItem $item): array => [$item->proposal_item_id => self::sourceLabel($item->source_type, $item->result, [])]),
        ];
    }

    private static function exerciseLabel(Exercise $exercise): string
    {
        return (string) $exercise->year;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  Collection<int, string>  $costCenters
     */
    private static function costCenter(array $result, Collection $costCenters): string
    {
        if (filled($result['cost_center_label'] ?? null)) {
            return (string) $result['cost_center_label'];
        }
        if (filled($result['cost_center_id'] ?? null)) {
            return $costCenters->get((int) $result['cost_center_id'], 'Non classificato');
        }
        $classification = collect(ProposalPlanData::rows($result['classification'] ?? null, 'classification'))->first();

        return filled($classification['cost_center_label'] ?? null)
            ? (string) $classification['cost_center_label']
            : 'Non classificato';
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  ReferenceMaps  $maps
     */
    private static function expenseOwner(array $result, array $maps): string
    {
        if (filled($result['project_item_id'] ?? null)) {
            return 'Progetto proposto · '.$maps['proposed_projects']->get((string) $result['project_item_id'], '—');
        }
        if (filled($result['project_id'] ?? null)) {
            return 'Progetto · '.$maps['projects']->get((int) $result['project_id'], '—');
        }
        if (filled($result['contract_id'] ?? null)) {
            return 'Contratto · '.$maps['contracts']->get((int) $result['contract_id'], '—');
        }

        return 'Autonoma';
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $baseline
     */
    private static function sourceLabel(ProposalSourceType $type, array $result, array $baseline): string
    {
        $field = $type === ProposalSourceType::Expense ? 'description' : 'title';

        return (string) ($result[$field] ?? $baseline[$field] ?? $result['origin_key'] ?? $baseline['origin_key'] ?? $type->label());
    }

    private static function sourceIcon(ProposalSourceType $type): string
    {
        return match ($type) {
            ProposalSourceType::Expense => 'heroicon-o-receipt-percent',
            ProposalSourceType::Project => 'heroicon-o-briefcase',
            ProposalSourceType::Contract => 'heroicon-o-document-text',
        };
    }

    /** @param array<string, mixed> $context */
    private static function actualTotal(array $context): string
    {
        if (isset($context['actual_total'])) {
            return Decimal::money((string) $context['actual_total']);
        }

        return Decimal::sum(collect(ProposalPlanData::rows($context['expenses'] ?? null, 'actual_context.expenses'))
            ->map(fn (array $expense): string => (string) ($expense['actual_total'] ?? '0.00')));
    }

    private static function estimateTotal(mixed $lines): string
    {
        return Decimal::sum(collect(ProposalPlanData::rows($lines, 'estimate_lines'))
            ->filter(fn (array $line): bool => ! ($line['annulled'] ?? false) && ! filled($line['annulled_at'] ?? null))
            ->map(fn (array $line): string => (string) ($line['amount'] ?? '0.00')));
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
        return ProjectState::tryFrom((string) $state)?->label() ?? ($state === null ? '—' : (string) $state);
    }

    private static function actionLabel(ProposalActionType $type): string
    {
        return match ($type) {
            ProposalActionType::CreateExpense => 'Creazione Spesa',
            ProposalActionType::CopyExpense => 'Copia Spesa',
            ProposalActionType::SetExpenseEstimates => 'Aggiornamento Stime della Spesa',
            ProposalActionType::SetExpenseOwner => 'Cambio Contenitore della Spesa',
            ProposalActionType::SetExpenseSupplier => 'Cambio Fornitore della Spesa',
            ProposalActionType::SetExpenseCostCenter => 'Cambio Centro di Costo della Spesa',
            ProposalActionType::ReverseExpense => 'Storno della Spesa',
            ProposalActionType::RestoreExpense => 'Ripristino della Spesa',
            ProposalActionType::CreateProject => 'Creazione Progetto',
            ProposalActionType::PlanProjectChildExpenses => 'Pianificazione Spese del Progetto',
            ProposalActionType::SetProjectCostCenter => 'Cambio Centro di Costo del Progetto',
            ProposalActionType::PlanProjectTransition => 'Transizione del Progetto',
            ProposalActionType::PlanProjectDeferral => 'Rinvio del Progetto',
            ProposalActionType::CreateProjectAllocation => 'Nuova Allocazione del Progetto',
            ProposalActionType::CreateContract => 'Creazione Contratto',
            ProposalActionType::AddContractCondition => 'Nuova Condizione del Contratto',
            ProposalActionType::ChangeContractEconomics => 'Modifica Economica del Contratto',
            ProposalActionType::PlanContractLifecycle => 'Ciclo di Vita del Contratto',
            ProposalActionType::SetContractRenewal => 'Rinnovo del Contratto',
            ProposalActionType::SetContractCostCenter => 'Cambio Centro di Costo del Contratto',
            ProposalActionType::LinkProjectContract => 'Relazione Progetto–Contratto',
        };
    }

    private static function cycle(mixed $cycle): string
    {
        return ContractCycleType::tryFrom((string) $cycle)?->label() ?? '—';
    }

    private static function attribution(mixed $mode): string
    {
        return ContractAttributionMode::tryFrom((string) $mode)?->label() ?? '—';
    }

    private static function lifecycleLabel(mixed $type): string
    {
        return match ($type) {
            'activation' => 'Attivazione',
            'reactivation' => 'Riattivazione',
            'cessation' => 'Cessazione',
            'expiry_cessation' => 'Cessazione a scadenza',
            'cancellation' => 'Annullamento prima dell’attivazione',
            'renewal' => 'Rinnovo',
            default => $type === null ? '—' : (string) $type,
        };
    }

    private static function date(mixed $date, string $empty = '—'): string
    {
        if ($date === null || $date === '') {
            return $empty;
        }

        return CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private static function dateTime(mixed $date, string $timezone): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        return CarbonImmutable::parse($date)->timezone($timezone)->format('d/m/Y H:i');
    }

    private static function money(mixed $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }

    private static function signedMoney(mixed $amount): string
    {
        $money = self::money(abs((float) $amount));

        return match (Decimal::compare(Decimal::money((string) $amount), '0.00')) {
            1 => '+'.$money,
            -1 => '−'.$money,
            default => $money,
        };
    }

    private static function deltaTone(mixed $amount): string
    {
        return match (Decimal::compare(Decimal::money((string) $amount), '0.00')) {
            1 => 'positive',
            -1 => 'negative',
            default => 'neutral',
        };
    }

    private static function decimalValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
    }
}
