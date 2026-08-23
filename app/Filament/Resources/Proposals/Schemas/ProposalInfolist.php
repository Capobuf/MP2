<?php

namespace App\Filament\Resources\Proposals\Schemas;

use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalPlanData;
use App\Domain\Proposals\ProposalPurpose;
use App\Domain\Proposals\ProposalReadiness;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProposalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proposta')->schema([
                TextEntry::make('exercise.year')->label('Esercizio'),
                TextEntry::make('purpose')->label('Finalità')->formatStateUsing(fn ($state): string => $state->label()),
                TextEntry::make('referenceBudget.version')->label('Budget di riferimento')->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state),
                TextEntry::make('status')->label('Stato')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                TextEntry::make('planned_allocation')->label('Allocato pianificato')->state(fn ($record): string => $record->plannedAllocation())->money('EUR', locale: 'it'),
                TextEntry::make('actual_notice')->label('Realtà effettiva')->state('Realtà effettiva in sola lettura: gli Effettivi non sono decisioni di piano.'),
                TextEntry::make('revision_context')->label('Contesto revisione')->state(fn (Proposal $record): string => $record->purpose === ProposalPurpose::Revision
                    ? 'La realtà corrente è la base; il Budget approvato è un confronto immutabile.'
                    : 'La realtà corrente è la base della proposta iniziale.'),
            ])->columns(3),
            Section::make('Elementi')->schema([
                RepeatableEntry::make('items')->label('')->schema([
                    TextEntry::make('source_type')->label('Tipo')->formatStateUsing(fn ($state): string => $state->label()),
                    TextEntry::make('proposal_item_id')->label('ProposalItemID')->copyable(),
                    TextEntry::make('copied_from_origin_key')->label('Lineage')->placeholder('Sorgente viva'),
                    TextEntry::make('baseline.actual_context.actual_total')->label('Effettivo (sola lettura)')->money('EUR', locale: 'it')->placeholder('—'),
                    TextEntry::make('baseline_allocation')->label('Allocato base')->state(fn (ProposalItem $record): string => self::itemImpact($record)['before'] ?? '0.00')->money('EUR', locale: 'it'),
                    TextEntry::make('result_allocation')->label('Allocato risultante')->state(fn (ProposalItem $record): string => self::itemImpact($record)['after'] ?? '0.00')->money('EUR', locale: 'it'),
                    TextEntry::make('result.incoming_deferral.mode')->label('Modalità rinvio risultante')->formatStateUsing(fn (mixed $state): string => ProjectDeferralMode::tryFrom((string) $state)?->label() ?? ProjectDeferralMode::None->label())->visible(self::projectItem(...)),
                    TextEntry::make('result.incoming_deferral.carryover_amount')->label('Riporto provvisorio risultante')->money('EUR', locale: 'it')->visible(self::projectItem(...)),
                    TextEntry::make('result.incoming_deferral.reprogrammed_amount')->label('Riprogrammato risultante')->money('EUR', locale: 'it')->visible(self::projectItem(...)),
                    TextEntry::make('result.incoming_deferral.destination_plans')->label('Anteprima nuove allocazioni da Riprogrammazione')->state(fn (ProposalItem $record): array => collect(ProposalPlanData::rows(data_get($record->result, 'incoming_deferral.destination_plans'), 'destination_plans'))->map(fn (array $plan): string => ($plan['description'] ?? 'Spesa').' · Copiata da '.($plan['copied_from_origin_key'] ?? '—').' · '.count($plan['estimate_lines'] ?? []).' Righe Stima')->all())->listWithLineBreaks()->placeholder('Nessuna')->visible(self::projectItem(...))->columnSpanFull(),
                    TextEntry::make('readiness_state')->label('Verifica')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                    TextEntry::make('readiness_reasons')->label('Motivi verifica')->state(fn (ProposalItem $record): string => collect(ProposalPlanData::rows($record->readiness_reasons, 'readiness_reasons'))->pluck('message')->implode(' · '))->placeholder('Nessun motivo'),
                    TextEntry::make('read_only_source')->label('Archivio')->formatStateUsing(fn (bool $state): string => $state ? 'Archiviato · sola lettura' : 'Operativo'),
                    TextEntry::make('baseline.plan_baseline')->label('Piano base')->formatStateUsing(self::json(...))->columnSpanFull()->wrap(),
                    TextEntry::make('result')->label('Piano risultante')->formatStateUsing(self::json(...))->columnSpanFull()->wrap(),
                ])->columns(3),
            ]),
            Section::make('Storico decisioni')->schema([
                RepeatableEntry::make('actionHistory')->label('')->schema([
                    TextEntry::make('sequence')->label('Ordine'),
                    TextEntry::make('action_type')->label('Azione')->formatStateUsing(fn ($state): string => $state->label()),
                    TextEntry::make('status')->label('Stato')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                    TextEntry::make('reason')->label('Motivazione')->placeholder('—'),
                    TextEntry::make('withdrawn_at')->label('Ritirata il')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('withdrawer.name')->label('Ritirata da')->placeholder('—'),
                    TextEntry::make('withdraw_reason')->label('Motivo ritiro')->placeholder('—'),
                    TextEntry::make('operation_id')->label('Identità operazione')->copyable(),
                ])->columns(4),
            ]),
            Section::make('Verifiche e impatti')->schema([
                TextEntry::make('readiness_summary')->label('Stati')->state(fn (Proposal $record): array => $record->items->groupBy(fn ($item): string => $item->readiness_state->label())->map->count()->map(fn (int $count, string $label): string => $label.': '.$count)->values()->all())->listWithLineBreaks(),
                TextEntry::make('readiness_blocks')->label('Blocchi')->state(function (Proposal $record): array {
                    $review = app(ProposalReadiness::class)->assessProposal($record);

                    return collect($review['blocks'])->map(fn (array $block): string => $block['code'].' · '.$block['message'])->all();
                })->listWithLineBreaks()->placeholder('Nessun blocco'),
                TextEntry::make('affected_exercises')->label('Esercizi interessati')->state(function (Proposal $record): array {
                    $review = app(ProposalReadiness::class)->assessProposal($record);

                    return collect($review['impacts'])->map(fn (array $impact): string => $impact['year'].' · '.($impact['will_apply'] ? 'Verrà applicato' : 'Storico invariato').' · Allocato '.$impact['allocation_before'].' → '.$impact['allocation_after'].' EUR · Delta '.$impact['allocation_delta'].' EUR'.($impact['historical_divergence'] ? ' · Divergenza: '.$impact['divergence_reason'] : ''))->all();
                })->listWithLineBreaks(),
                TextEntry::make('affected_sources')->label('Sorgenti interessate')->state(function (Proposal $record): array {
                    $review = app(ProposalReadiness::class)->assessProposal($record);

                    return collect($review['impacts'])->flatMap(fn (array $impact): array => collect(ProposalPlanData::rows($impact['sources'] ?? null, 'sources'))->map(fn (array $source): string => $impact['year'].' · '.$source['source_type'].' · '.($source['origin_key'] ?? $source['proposal_item_id']).' · '.$source['before'].' → '.$source['after'].' EUR · stato '.($source['state_before'] ?? 'assente').' → '.($source['state_after'] ?? 'assente'))->all())->all();
                })->listWithLineBreaks(),
                TextEntry::make('readiness_warnings')->label('Avvisi')->state(function (Proposal $record): array {
                    $review = app(ProposalReadiness::class)->assessProposal($record);

                    return collect($review['impacts'])->flatMap(fn (array $impact): array => $impact['warnings'])->unique()->values()->all();
                })->listWithLineBreaks()->placeholder('Nessun avviso'),
                TextEntry::make('unchanged_budgets')->label('Budget che restano invariati')->state(function (Proposal $record): array {
                    $review = app(ProposalReadiness::class)->assessProposal($record);

                    return collect($review['impacts'])->flatMap(fn (array $impact): array => collect(ProposalPlanData::rows($impact['unchanged_budgets'] ?? null, 'unchanged_budgets'))->map(fn (array $budget): string => 'Esercizio '.$impact['year'].' · Budget #'.$budget['budget_id'].' v'.$budget['version'])->all())->all();
                })->listWithLineBreaks()->placeholder('Nessun Budget precedente interessato'),
                TextEntry::make('stale_proposals')->label('Altre Proposte da riallineare')->state(function (Proposal $record): array {
                    $review = app(ProposalReadiness::class)->assessProposal($record);

                    return collect($review['impacts'])->flatMap(fn (array $impact): array => collect(ProposalPlanData::rows($impact['stale_proposals'] ?? null, 'stale_proposals'))->map(fn (array $proposal): string => 'Proposta #'.$proposal['proposal_id'].' · Esercizio #'.$proposal['exercise_id'])->all())->unique()->values()->all();
                })->listWithLineBreaks()->placeholder('Nessuna Proposta concorrente'),
                TextEntry::make('realignment_boundary')->label('Risoluzione')->state('Le sorgenti cambiate richiedono un riallineamento completo; le nuove sorgenti richiedono una presa visione esplicita.'),
            ])->columns(2),
        ]);
    }

    /** @return array<string, mixed> */
    private static function itemImpact(ProposalItem $item): array
    {
        $impacts = ProposalImpactPlan::build($item->proposal);
        $main = collect($impacts)->firstWhere('exercise_id', $item->proposal->exercise_id);
        if ($main === null) {
            return [];
        }

        return collect(ProposalPlanData::rows($main['sources'] ?? null, 'sources'))->firstWhere('proposal_item_id', $item->proposal_item_id) ?? [];
    }

    private static function json(mixed $state): string
    {
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function projectItem(ProposalItem $record): bool
    {
        return $record->source_type->value === 'project';
    }
}
