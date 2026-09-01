<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Operations\UploadAttachment;
use App\Actions\Proposals\AcknowledgeProposalSource;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CopyExpenseIntoProposal;
use App\Actions\Proposals\DiscardProposal;
use App\Actions\Proposals\IncludeProposalSource;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Actions\Proposals\PlanProposalRelation;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Domain\Proposals\ExpensePlan;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalPlanData;
use App\Domain\Proposals\ProposalPurpose;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Domain\Proposals\ProposalSourceType;
use App\Filament\Forms\AttachmentUpload;
use App\Filament\Forms\DecimalInput;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewProposal extends ViewRecord
{
    protected static string $resource = ProposalResource::class;

    public string $approvalOperationId = '';

    public string $evidenceOperationId = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->approvalOperationId = (string) Str::uuid();
        $this->evidenceOperationId = (string) Str::uuid();
    }

    public function getHeader(): ?View
    {
        $proposal = $this->proposal();

        return view('filament.resources.proposals.components.object-header', [
            'proposal' => $proposal,
            'proposalsUrl' => ProposalResource::getUrl('index', tenant: $proposal->company),
        ]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'mp2-object-page mp2-proposal-object-page'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')->label('Timeline della Proposta')->icon('heroicon-m-chart-bar')->color('gray')->outlined()->url(fn (): string => CompanyAudit::getUrl(['tenant' => $this->proposal()->company, 'proposal' => $this->proposal()->id])),
            Action::make('viewBudget')->label(fn (): string => 'Apri Budget v'.$this->proposal()->budget()->value('version'))->icon('heroicon-m-banknotes')->url(fn (): string => BudgetResource::getUrl('view', ['record' => $this->proposal()->budget()->sole()], tenant: $this->proposal()->company))->visible(fn (): bool => $this->proposal()->budget()->exists()),
            Action::make('approveBudget')->label(fn (): string => 'Approva e crea Budget v'.$this->nextBudgetVersion())->color('success')->requiresConfirmation()->modalHeading(fn (): string => 'Approva '.($this->proposal()->purpose === ProposalPurpose::Revision ? 'Revisione' : 'Proposta').' e crea Budget v'.$this->nextBudgetVersion())->modalDescription(fn (): string => 'La conferma rivalida e applica atomicamente il piano. '.$this->approvalSummary())->modalSubmitActionLabel(fn (): string => 'Approva e crea Budget v'.$this->nextBudgetVersion())->visible(fn (): bool => $this->canApprove())->disabled(fn (): bool => ! $this->approvalReady())->tooltip(fn (): ?string => $this->approvalReady() ? null : 'Risolvere tutti i blocchi di verifica prima dell’approvazione.')->form([
                Placeholder::make('final_impact')->label('Impatto Finale da Approvare')->content(fn (): string => $this->approvalSummary()), TextInput::make('external_subject')->label('Soggetto Approvante Esterno')->maxLength(255), TextInput::make('external_venue')->label('Sede o Verbale')->maxLength(255), Textarea::make('reason')->label('Motivazione della Revisione')->required(fn (): bool => $this->proposal()->purpose === ProposalPurpose::Revision), AttachmentUpload::make('new_evidence')->label('Nuova Evidenza Privata')->storeFiles(false), Select::make('attachment_ids')->label('Evidenze Già Presenti')->multiple()->options(fn (): array => Attachment::query()->where('company_id', $this->proposal()->company_id)->whereNull('detached_at')->orderBy('original_name')->pluck('original_name', 'id')->all()), Hidden::make('evidence_operation_id')->default(fn (): string => $this->evidenceOperationId), Hidden::make('operation_id')->default(fn (): string => $this->approvalOperationId),
            ])->action(function (array $data): void {
                $ids = array_map('intval', $data['attachment_ids'] ?? []);
                $file = $data['new_evidence'] ?? null;
                if ($file instanceof UploadedFile) {
                    $attachment = app(UploadAttachment::class)->execute($this->actor(), $this->proposal(), $file, $data['evidence_operation_id']);
                    $ids[] = $attachment->id;
                }
                $budget = app(ApproveProposal::class)->execute($this->actor(), $this->proposal(), $data['operation_id'], ['external_subject' => filled($data['external_subject'] ?? null) ? trim($data['external_subject']) : null, 'external_venue' => filled($data['external_venue'] ?? null) ? trim($data['external_venue']) : null, 'reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null], array_values(array_unique($ids)));
                $this->redirect(BudgetResource::getUrl('view', ['record' => $budget], tenant: $this->proposal()->company));
            }),
            Action::make('reviewReadiness')->label('Ricalcola Verifiche')->visible(fn (): bool => $this->canPlan())->form([Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])->action(function (array $data): void {
                $this->record = app(ReviewProposalReadiness::class)->execute($this->actor(), $this->proposal(), $data['operation_id']);
                $this->refreshProposal('Verifiche Ricalcolate');
            }),
            Action::make('discardProposal')->label('Scarta Proposta')->color('danger')->visible(fn (): bool => $this->canPlan())->requiresConfirmation()->modalDescription('Lo scarto conserva la Proposta e il suo storico. La realtà viva e tutti i Budget restano invariati.')->form([
                Textarea::make('reason')->label('Motivazione')->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                $this->record = app(DiscardProposal::class)->execute($this->actor(), $this->proposal(), $data['reason'], $data['operation_id']);
                $this->refreshProposal('Proposta Scartata senza Modificare la Realtà');
            }),
            Action::make('reloadReality')->label('Ricarica Realtà')->visible(fn (): bool => $this->canRealign())->requiresConfirmation()->modalDescription('Tutte le decisioni che toccano la sorgente saranno ritirate. La realtà corrente sostituirà integralmente piano base e risultato.')->form([
                Select::make('item_id')->label('Sorgente da Riallineare')->options(fn (): array => $this->realignmentItemOptions())->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
            ])->action(function (array $data): void {
                app(RealignProposalItem::class)->execute($this->actor(), $this->proposal(), $this->realignmentItem((int) $data['item_id']), ProposalRealignmentChoice::Reload, null, [], $data['operation_id'], (int) $data['proposal_revision']);
                $this->refreshProposal('Realtà Ricaricata per l’Intera Sorgente');
            }),
            Action::make('keepProposal')->label('Mantieni Proposta')->visible(fn (): bool => $this->canRealign())->requiresConfirmation()->modalDescription('La realtà corrente diventa il nuovo piano base e tutte le decisioni attive della sorgente vengono rivalidate e riapplicate.')->form([
                Select::make('item_id')->label('Sorgente da Riallineare')->options(fn (): array => $this->realignmentItemOptions())->required(),
                Textarea::make('reason')->label('Motivazione')->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
            ])->action(function (array $data): void {
                app(RealignProposalItem::class)->execute($this->actor(), $this->proposal(), $this->realignmentItem((int) $data['item_id']), ProposalRealignmentChoice::Keep, $data['reason'], [], $data['operation_id'], (int) $data['proposal_revision']);
                $this->refreshProposal('Decisioni Riapplicate alla Realtà Corrente');
            }),
            Action::make('manualRealignment')->label('Rivedi Manualmente')->visible(fn (): bool => $this->canRealign())->requiresConfirmation()->modalDescription('Selezionare le decisioni da mantenere. Le altre saranno ritirate senza riscriverne lo storico.')->form([
                Select::make('item_id')->label('Sorgente da Riallineare')->options(fn (): array => $this->realignmentItemOptions())->required()->live(),
                CheckboxList::make('retained_action_ids')->label('Decisioni da Mantenere')->options(fn (): array => $this->proposal()->actions()->get()->mapWithKeys(fn ($action): array => [$action->id => '#'.$action->sequence.' · '.$action->action_type->label()])->all()),
                Textarea::make('reason')->label('Nota di Revisione'),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
            ])->action(function (array $data): void {
                app(RealignProposalItem::class)->execute($this->actor(), $this->proposal(), $this->realignmentItem((int) $data['item_id']), ProposalRealignmentChoice::Manual, $data['reason'] ?? null, array_map('intval', $data['retained_action_ids'] ?? []), $data['operation_id'], (int) $data['proposal_revision']);
                $this->refreshProposal('Revisione Manuale Confermata');
            }),
            Action::make('acknowledgeSource')->label('Prendi Visione')->visible(fn (): bool => $this->canAcknowledge())->requiresConfirmation()->modalDescription('Conferma la realtà corrente della sorgente. La presa visione non crea una modifica economica e non tocca gli Effettivi; eventuali variazioni di Stima vanno preparate prima con un’azione tipizzata.')->form([
                Select::make('item_id')->label('Nuova Sorgente')->options(fn (): array => $this->acknowledgementItemOptions())->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
            ])->action(function (array $data): void {
                $item = $this->proposal()->items()->where('readiness_state', 'to_review')->findOrFail((int) $data['item_id']);
                app(AcknowledgeProposalSource::class)->execute($this->actor(), $this->proposal(), $item, $data['operation_id'], (int) $data['proposal_revision']);
                $this->refreshProposal('Sorgente Presa in Visione');
            }),
            ActionGroup::make([
                Action::make('includeClosedProject')->label('Seleziona Progetto da Riaprire')->visible(fn (): bool => $this->canPlan())->form([Select::make('source_id')->label('Progetto Chiuso o Cancellato')->options(fn (): array => $this->eligibleProjectOptions())->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(IncludeProposalSource::class)->execute($this->actor(), $this->proposal(), ProposalSourceType::Project, (int) $data['source_id'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Progetto Selezionato: Pianificare Ora la Riapertura');
                }),
                Action::make('includeTerminatedContract')->label('Seleziona Contratto da Riattivare')->visible(fn (): bool => $this->canPlan())->form([Select::make('source_id')->label('Contratto Cessato o Annullato')->options(fn (): array => $this->eligibleContractOptions())->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(IncludeProposalSource::class)->execute($this->actor(), $this->proposal(), ProposalSourceType::Contract, (int) $data['source_id'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Contratto Selezionato: Pianificare Ora Condizione e Riattivazione');
                }),
                Action::make('planProjectDeferral')->label('Rinvio')->visible(fn (): bool => $this->canPlan())->modalDescription('Scegliere una sola modalità. Riporto e Riprogrammazione usano la disponibilità canonica dell’Esercizio immediatamente precedente; gli Effettivi restano in sola lettura e i Budget esistenti invariati.')->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->deferralProjectOptions())->live()->required(),
                    Select::make('mode')->label('Modalità')->options([
                        ProjectDeferralMode::None->value => ProjectDeferralMode::None->label(),
                        ProjectDeferralMode::Carryover->value => ProjectDeferralMode::Carryover->label(),
                        ProjectDeferralMode::Reprogramming->value => ProjectDeferralMode::Reprogramming->label(),
                    ])->disableOptionWhen(fn (string $value, Get $get): bool => $this->deferralModeDisabled($value, (int) $get('item_id')))->live()->required(),
                    TextInput::make('carryover_amount')->label('Riporto Provvisorio')->numeric()->minValue(0.01)->prefix('€')->visible(fn (Get $get): bool => $get('mode') === 'carryover')->required(fn (Get $get): bool => $get('mode') === 'carryover'),
                    Repeater::make('source_estimate_reductions')->label('Riduzioni Stime Origine')->schema([
                        Select::make('source_line_id')->label('Riga Stima Origine')->options(fn (Get $get): array => $this->proposalDeferralLineOptions((int) $get('../../item_id')))->required(),
                        TextInput::make('reduction_amount')->label('Riduzione')->numeric()->minValue(0.01)->prefix('€')->required(),
                        Select::make('destination_supplier_id')->label('Fornitore Destinazione')->options(fn (): array => ['none' => 'Nessun Fornitore'] + Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->required(),
                    ])->columns(3)->minItems(1)->visible(fn (Get $get): bool => $get('mode') === 'reprogramming')->required(fn (Get $get): bool => $get('mode') === 'reprogramming'),
                    Placeholder::make('deferral_formula')->label('Valori e Impatto')->content(fn (Get $get): string => $this->proposalDeferralSummary($get)),
                    Textarea::make('reason')->label('Motivazione del Rinvio')->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->whereNotNull('project_id')->findOrFail((int) $data['item_id']);
                    $source = $this->previousExercise();
                    if ($source === null) {
                        throw ValidationException::withMessages(['exercise' => 'Non esiste un Esercizio immediatamente precedente.']);
                    }
                    app(PlanProjectDeferral::class)->execute($this->actor(), $this->proposal(), $item, [
                        'source_exercise_id' => $source->id,
                        'destination_exercise_id' => $this->proposal()->exercise_id,
                        'mode' => $data['mode'],
                        'carryover_amount' => $data['carryover_amount'] ?? null,
                        'source_estimate_reductions' => $this->normalizeDeferralReductions($data['source_estimate_reductions'] ?? []),
                    ], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Rinvio del Progetto Pianificato');
                }),
                Action::make('createPlannedExpense')->label('Nuova Spesa Pianificata')->visible(fn (): bool => $this->canPlan())->form([
                    TextInput::make('description')->label('Descrizione')->required()->maxLength(255), Textarea::make('notes')->label('Note'),
                    Select::make('project_reference')->label('Progetto di Destinazione')->options(fn (): array => $this->newExpenseProjectReferenceOptions())->default('autonomous')->required(),
                    Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->placeholder('Nessun Fornitore'),
                    Select::make('cost_center_id')->label('Centro di Costo Diretto (Solo Autonoma)')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificata'),
                    Repeater::make('estimate_lines')->label('Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->modalDescription('Per riposizionare piano residuo, ridurre prima le Stime originarie e creare qui una Spesa distinta: nessun matching con gli Effettivi.')->action(function (array $data): void {
                    $actor = $this->actor();
                    app(PlanExpense::class)->create($actor, $this->proposal(), ['description' => $data['description'], 'notes' => $data['notes'] ?? null, 'exercise_id' => $this->proposal()->exercise_id, 'supplier_id' => filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null, 'cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null, ...$this->parseExpenseProjectReference($data['project_reference']), 'estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines'])], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Spesa Pianificata Aggiunta');
                }),
                Action::make('createProjectAllocation')->label('Nuova Allocazione')->visible(fn (): bool => $this->canPlan())->modalDescription('Aumenta le Stime di destinazione in modo indipendente: non è Riporto né Riprogrammazione e non sarà rimossa da una futura inversione della Riprogrammazione.')->form([
                    Select::make('project_id')->label('Progetto Vivo di Destinazione')->options(fn (): array => Project::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('title')->pluck('title', 'id')->all())->required(),
                    TextInput::make('description')->label('Descrizione')->required()->maxLength(255),
                    Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->placeholder('Nessun Fornitore'),
                    Repeater::make('estimate_lines')->label('Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Textarea::make('reason')->label('Nota')->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanExpense::class)->create($this->actor(), $this->proposal(), [
                        'description' => $data['description'], 'notes' => null, 'exercise_id' => $this->proposal()->exercise_id,
                        'supplier_id' => filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null,
                        'cost_center_id' => null, 'project_id' => (int) $data['project_id'], 'project_item_id' => null,
                        'estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines']),
                    ], $data['reason'], $data['operation_id'], (int) $data['proposal_revision'], ProposalActionType::CreateProjectAllocation);
                    $this->refreshProposal('Nuova Allocazione del Progetto Aggiunta');
                }),
                Action::make('planExpenseEstimates')->label('Modifica Stime Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(),
                    Repeater::make('estimate_lines')->label('Sostituzione Completa Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines'])], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Stime Pianificate Aggiornate');
                }),
                Action::make('planExpenseOwner')->label('Sposta Piano Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa Priva di Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(),
                    Select::make('exercise_id')->label('Esercizio Aperto')->options(fn (): array => Exercise::query()->where('company_id', $this->proposal()->company_id)->open()->orderBy('year')->pluck('year', 'id')->all())->default(fn (): int => $this->proposal()->exercise_id)->required(),
                    Select::make('project_reference')->label('Contenitore Piano')->options(fn (): array => $this->expenseProjectReferenceOptions())->default('autonomous')->required(),
                    Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->modalDescription('Muove soltanto una Spesa priva di Effettivi tra autonomia e Progetti. Il cambio anno di una Spesa di Progetto è Riprogrammazione S8 e resta indisponibile.')->action(function (array $data): void {
                    $item = $this->expenseItem((int) $data['item_id']);
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetExpenseOwner, ['exercise_id' => (int) $data['exercise_id'], ...$this->parseExpenseProjectReference($data['project_reference'])], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Contenitore del Piano Aggiornato');
                }),
                Action::make('planExpenseSupplier')->label('Cambia Fornitore Piano Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa Autonoma o di Progetto senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->placeholder('Nessun Fornitore'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::SetExpenseSupplier, ['supplier_id' => filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Fornitore del Piano Aggiornato');
                }),
                Action::make('planExpenseCostCenter')->label('Cambia Centro di Costo Piano Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa Autonoma senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cost_center_id')->label('Centro di Costo Diretto')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificata'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::SetExpenseCostCenter, ['cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Centro di Costo del Piano Aggiornato');
                }),
                Action::make('reversePlannedExpense')->label('Storna Spesa nel Piano')->visible(fn (): bool => $this->canPlan())->requiresConfirmation()->form([Select::make('item_id')->label('Spesa Priva di Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Textarea::make('reason')->label('Motivazione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::ReverseExpense, ['reason' => $data['reason']], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Storno Pianificato');
                }),
                Action::make('restorePlannedExpense')->label('Ripristina Spesa nel Piano')->visible(fn (): bool => $this->canPlan())->requiresConfirmation()->form([Select::make('item_id')->label('Spesa Stornata Priva di Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Textarea::make('reason')->label('Motivazione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::RestoreExpense, ['reason' => $data['reason']], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Ripristino Pianificato');
                }),
                Action::make('copyExpense')->label('Copia Spesa Autonoma')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('source_expense_id')->label('Spesa Autonoma di Altro Esercizio')->options(fn (): array => Expense::query()->where('company_id', $this->proposal()->company_id)->where('exercise_id', '<>', $this->proposal()->exercise_id)->whereNull('project_id')->whereNull('contract_id')->whereNull('reversed_at')->orderBy('description')->pluck('description', 'id')->all())->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(CopyExpenseIntoProposal::class)->execute($this->actor(), $this->proposal(), Expense::query()->findOrFail($data['source_expense_id']), $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Spesa Copiata con Nuova Identità e Lineage');
                }),
                Action::make('createPlannedProject')->label('Nuovo Progetto Pianificato')->visible(fn (): bool => $this->canPlan())->form([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255), Textarea::make('description')->label('Descrizione'), Textarea::make('notes')->label('Note'),
                    Hidden::make('initial_state')->default('planned'), DatePicker::make('initial_effective_date')->label('Efficace dal')->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanProject::class)->create($this->actor(), $this->proposal(), ['title' => $data['title'], 'description' => $data['description'] ?? null, 'notes' => $data['notes'] ?? null, 'initial_state' => $data['initial_state'], 'initial_effective_date' => $data['initial_effective_date'], 'exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => null], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Progetto Pianificato Aggiunto');
                }),
                Action::make('planProjectTransition')->label('Pianifica Stato Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('from_state')->label('Da')->options(['planned' => 'Pianificato', 'open' => 'Aperto', 'closed' => 'Chiuso', 'cancelled' => 'Cancellato'])->required(), Select::make('to_state')->label('A')->options(['planned' => 'Pianificato', 'open' => 'Aperto', 'closed' => 'Chiuso', 'cancelled' => 'Cancellato'])->required(), DatePicker::make('effective_date')->label('Data Efficacia')->required(), Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanProjectTransition, ['from_state' => $data['from_state'], 'to_state' => $data['to_state'], 'effective_date' => $data['effective_date'], 'reason' => $data['reason'] ?? null], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Stato Progetto Pianificato');
                }),
                Action::make('planProjectChildExpenses')->label('Associa Spese Pianificate al Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(),
                    Select::make('child_item_ids')->label('Nuove Spese della Stessa Proposta')->multiple()->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->whereNull('expense_id')->pluck('proposal_item_id', 'proposal_item_id')->all())->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanProjectChildExpenses, ['child_item_ids' => array_values($data['child_item_ids']), 'existing_expenses' => []], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Spese Pianificate Associate al Progetto');
                }),
                Action::make('planProjectExpenseEstimates')->label('Modifica Stime Figlie Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(),
                    Select::make('expense_id')->label('Spesa Figlia Esistente')->options(fn (): array => Expense::query()->where('company_id', $this->proposal()->company_id)->where('exercise_id', $this->proposal()->exercise_id)->whereNotNull('project_id')->whereNull('reversed_at')->orderBy('description')->pluck('description', 'id')->all())->required(),
                    Repeater::make('estimate_lines')->label('Sostituzione Completa Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanProjectChildExpenses, ['child_item_ids' => [], 'existing_expenses' => [['expense_id' => (int) $data['expense_id'], 'estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines'])]]], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Stime Figlie del Progetto Aggiornate');
                }),
                Action::make('planProjectCostCenter')->label('Cambia Centro di Costo Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cost_center_id')->label('Centro di Costo Annuale')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificato'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetProjectCostCenter, ['exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Centro di Costo del Progetto Aggiornato');
                }),
                Action::make('createPlannedContract')->label('Nuovo Contratto Pianificato')->visible(fn (): bool => $this->canPlan())->form([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255), Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->required(), DatePicker::make('contractual_start_date')->label('Inizio Contrattuale')->required(), DatePicker::make('next_expiry_date')->label('Prossima Scadenza'), Toggle::make('automatic_renewal')->label('Rinnovo Automatico')->default(false), TextInput::make('renewal_duration_months')->label('Durata Rinnovo (Mesi)')->integer()->minValue(1), TextInput::make('notice_days')->label('Preavviso (Giorni)')->integer()->minValue(0)->default(0), Textarea::make('notes')->label('Note'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanContract::class)->create($this->actor(), $this->proposal(), ['title' => $data['title'], 'notes' => $data['notes'] ?? null, 'supplier_id' => (int) $data['supplier_id'], 'contractual_start_date' => $data['contractual_start_date'], 'next_expiry_date' => $data['next_expiry_date'] ?? null, 'automatic_renewal' => (bool) ($data['automatic_renewal'] ?? false), 'renewal_duration_months' => filled($data['renewal_duration_months'] ?? null) ? (int) $data['renewal_duration_months'] : null, 'notice_days' => (int) ($data['notice_days'] ?? 0), 'exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => null], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Contratto Pianificato Aggiunto');
                }),
                Action::make('addContractCondition')->label('Aggiungi Condizione Contratto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cycle')->label('Ciclo')->options(['monthly' => 'Mensile', 'quarterly' => 'Trimestrale', 'semiannual' => 'Semestrale', 'annual' => 'Annuale'])->required(), Select::make('attribution_mode')->label('Attribuzione')->options(['cycle_start' => 'Inizio Ciclo', 'cycle_end' => 'Fine Ciclo'])->required(), DecimalInput::make('amount')->label('Importo Netto IVA')->minValue(0)->required(), DatePicker::make('valid_from')->label('Valida dal')->required(), DatePicker::make('valid_to')->label('Valida fino al'), Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::AddContractCondition, ['cycle' => $data['cycle'], 'attribution_mode' => $data['attribution_mode'], 'amount' => number_format((float) $data['amount'], 2, '.', ''), 'valid_from' => $data['valid_from'], 'valid_to' => $data['valid_to'] ?? null, 'reason' => $data['reason'] ?? null], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Condizione Contrattuale Pianificata');
                }),
                Action::make('changeContractEconomics')->label('Modifica Economica Contratto')->visible(fn (): bool => $this->canPlan())->modalDescription('Sono mostrate data richiesta, data minima e data efficace. Prorata applicato: no.')->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), TextInput::make('condition_id')->label('ID Condizione')->integer()->required(), DecimalInput::make('amount')->label('Nuovo Importo Netto IVA')->minValue(0)->required(), Select::make('cycle')->label('Nuovo Ciclo')->options(['monthly' => 'Mensile', 'quarterly' => 'Trimestrale', 'semiannual' => 'Semestrale', 'annual' => 'Annuale'])->required(), Select::make('attribution_mode')->label('Nuova Attribuzione')->options(['cycle_start' => 'Inizio ciclo', 'cycle_end' => 'Fine ciclo'])->required(), DatePicker::make('requested_date')->label('Data Richiesta')->required(), DatePicker::make('confirmed_effective_date')->label('Data Efficace Applicabile Confermata')->required(), Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->modalDescription('Il server ricalcola Data minima ed effettiva dal confine del ciclo. Se la conferma non coincide, mostra la data esatta e non salva. Prorata applicato: no.')->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::ChangeContractEconomics, ['condition_id' => (int) $data['condition_id'], 'amount' => number_format((float) $data['amount'], 2, '.', ''), 'cycle' => $data['cycle'], 'attribution_mode' => $data['attribution_mode'], 'requested_date' => $data['requested_date'], 'confirmed_effective_date' => $data['confirmed_effective_date'], 'reason' => $data['reason'] ?? null], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Modifica Economica Pianificata');
                }),
                Action::make('planContractLifecycle')->label('Pianifica Cessazione o Riattivazione')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('type')->label('Evento')->options(['cessation' => 'Cessazione', 'reactivation' => 'Riattivazione'])->required(), DatePicker::make('declared_contractual_date')->label('Ultimo Giorno Attivo / Nuova Data di Inizio')->required(), DatePicker::make('effective_date')->label('Data Efficace')->required(), DatePicker::make('next_expiry_date')->label('Nuova Prossima Scadenza (Riattivazione)'), Textarea::make('reason')->label('Motivazione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanContractLifecycle, ['type' => $data['type'], 'declared_contractual_date' => $data['declared_contractual_date'], 'effective_date' => $data['effective_date'], 'next_expiry_date' => $data['next_expiry_date'] ?? null, 'reason' => $data['reason']], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Evento Contrattuale Pianificato');
                }),
                Action::make('planContractRenewal')->label('Modifica Rinnovo e Scadenza')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), DatePicker::make('effective_from')->label('Configurazione Efficace dal')->required(), DatePicker::make('expiry_anchor_date')->label('Prossima Scadenza'), Toggle::make('automatic_renewal')->label('Rinnovo Automatico')->default(false), TextInput::make('renewal_duration_months')->label('Durata Rinnovo (Mesi)')->integer()->minValue(1), TextInput::make('notice_days')->label('Preavviso (Giorni)')->integer()->minValue(0), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetContractRenewal, ['effective_from' => $data['effective_from'], 'expiry_anchor_date' => $data['expiry_anchor_date'] ?? null, 'automatic_renewal' => (bool) ($data['automatic_renewal'] ?? false), 'renewal_duration_months' => filled($data['renewal_duration_months'] ?? null) ? (int) $data['renewal_duration_months'] : null, 'notice_days' => filled($data['notice_days'] ?? null) ? (int) $data['notice_days'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Rinnovo Contrattuale Pianificato');
                }),
                Action::make('planContractCostCenter')->label('Cambia Centro di Costo Contratto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cost_center_id')->label('Centro di Costo Annuale')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificato'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetContractCostCenter, ['exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Centro di Costo del Contratto Aggiornato');
                }),
                Action::make('linkProjectContract')->label('Collega Progetto e Contratto')->visible(fn (): bool => $this->canPlan())->modalDescription('Collegamento informativo “Collegato a”, senza effetto economico.')->form([
                    Select::make('project_reference')->label('Progetto')->options(fn (): array => $this->projectReferenceOptions())->required(), Select::make('contract_reference')->label('Contratto')->options(fn (): array => $this->contractReferenceOptions())->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanProposalRelation::class)->execute($this->actor(), $this->proposal(), [...$this->parseReference($data['project_reference'], 'project'), ...$this->parseReference($data['contract_reference'], 'contract')], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Progetto e Contratto Collegati');
                }),
            ])->label('Azioni di Piano')->button(),
        ];
    }

    /** @return array<int, mixed> */
    private function estimateLineSchema(): array
    {
        return [Hidden::make('proposal_line_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('line_id')->default(null), DecimalInput::make('amount')->label('Importo Netto IVA')->minValue(0)->required(), Textarea::make('note')->label('Nota'), Toggle::make('annulled')->label('Annullata')->default(false)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEstimateLines(array $lines): array
    {
        return collect($lines)->map(fn (array $line): array => ['proposal_line_id' => $line['proposal_line_id'], 'line_id' => filled($line['line_id'] ?? null) ? (int) $line['line_id'] : null, 'amount' => number_format((float) $line['amount'], 2, '.', ''), 'note' => filled($line['note'] ?? null) ? trim($line['note']) : null, 'annulled' => (bool) ($line['annulled'] ?? false)])->all();
    }

    private function proposal(): Proposal
    {
        if (! $this->record instanceof Proposal) {
            throw new \UnexpectedValueException('Invalid Proposal record.');
        }

        return $this->record;
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function expenseItem(int $id): ProposalItem
    {
        return ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'expense')->findOrFail($id);
    }

    private function canPlan(): bool
    {
        return $this->proposal()->status->value === 'draft' && auth()->user()?->can('update', $this->proposal()) === true;
    }

    private function canRealign(): bool
    {
        return $this->canPlan() && $this->proposal()->items()->where('readiness_state', 'to_realign')->exists();
    }

    private function canAcknowledge(): bool
    {
        return $this->canPlan() && $this->proposal()->items()->where('readiness_state', 'to_review')->exists();
    }

    /** @return array<int, string> */
    private function acknowledgementItemOptions(): array
    {
        return $this->proposal()->items()->where('readiness_state', 'to_review')->orderBy('id')->get()
            ->mapWithKeys(fn (ProposalItem $item): array => [$item->id => $item->source_type->label().' · '.$item->proposal_item_id])
            ->all();
    }

    /** @return array<int, string> */
    private function realignmentItemOptions(): array
    {
        return $this->proposal()->items()->where('readiness_state', 'to_realign')->orderBy('id')->get()
            ->mapWithKeys(fn (ProposalItem $item): array => [$item->id => $item->source_type->label().' · '.$item->proposal_item_id])
            ->all();
    }

    private function realignmentItem(int $id): ProposalItem
    {
        return $this->proposal()->items()->where('readiness_state', 'to_realign')->findOrFail($id);
    }

    private function canApprove(): bool
    {
        return $this->proposal()->status->value === 'draft' && auth()->user()?->can('approve', $this->proposal()) === true;
    }

    private function approvalReady(): bool
    {
        return app(ProposalReadiness::class)->assessProposal($this->proposal())['ready'];
    }

    private function nextBudgetVersion(): int
    {
        $proposal = $this->proposal();

        return $proposal->reference_budget_id === null ? 1 : $proposal->referenceBudget->version + 1;
    }

    private function approvalSummary(): string
    {
        $review = app(ProposalReadiness::class)->assessProposal($this->proposal());
        $exercises = collect($review['impacts'])->map(fn (array $impact): string => $impact['year'].' '.$impact['allocation_before'].'→'.$impact['allocation_after'].' EUR ('.$impact['allocation_delta'].' EUR), '.count($impact['sources']).' sorgenti')->implode('; ');
        $warnings = collect($review['impacts'])->flatMap(fn (array $impact): array => $impact['warnings'])->unique()->implode(' ');
        $blocks = collect($review['blocks'])->pluck('message')->implode(' ');

        return 'Esercizi e sorgenti: '.$exercises.'. '.($warnings === '' ? '' : 'Avvisi: '.$warnings.' ').($blocks === '' ? 'Nessun blocco.' : 'Blocchi: '.$blocks);
    }

    /** @return array<int, string> */
    private function eligibleProjectOptions(): array
    {
        $date = $this->proposal()->exercise->year.'-01-01';
        $included = $this->proposal()->items()->whereNotNull('project_id')->pluck('project_id');

        return Project::query()->where('company_id', $this->proposal()->company_id)->whereNotIn('id', $included)->with('transitions')->orderBy('title')->get()->filter(fn (Project $project): bool => in_array($project->stateAtDate($date), [ProjectState::Closed, ProjectState::Cancelled], true))->pluck('title', 'id')->all();
    }

    /** @return array<int, string> */
    private function eligibleContractOptions(): array
    {
        $date = $this->proposal()->exercise->year.'-01-01';
        $included = $this->proposal()->items()->whereNotNull('contract_id')->pluck('contract_id');

        return Contract::query()->where('company_id', $this->proposal()->company_id)->whereNotIn('id', $included)->with(['lifecycleFacts', 'renewalConfigurations'])->orderBy('title')->get()->filter(fn (Contract $contract): bool => in_array($contract->stateAtDate($date), [ContractState::Cessated, ContractState::Cancelled], true))->pluck('title', 'id')->all();
    }

    private function refreshProposal(string $title): void
    {
        $this->record = $this->proposal()->refresh()->load(['exercise', 'creator', 'referenceBudget', 'items', 'actions', 'actionHistory']);
        Notification::make()->title($title)->success()->send();
    }

    /** @return array<string, string> */
    private function projectReferenceOptions(): array
    {
        return [...$this->proposal()->items()->where('source_type', 'project')->get()->mapWithKeys(fn (ProposalItem $item): array => ['item:'.$item->proposal_item_id => 'Proposta · '.$item->proposal_item_id])->all(), ...Project::query()->where('company_id', $this->proposal()->company_id)->orderBy('title')->get()->mapWithKeys(fn (Project $project): array => ['origin:'.$project->originKey() => 'Vivo · '.$project->title])->all()];
    }

    /** @return array<string, string> */
    private function expenseProjectReferenceOptions(): array
    {
        return ['autonomous' => 'Spesa Autonoma', ...$this->projectReferenceOptions()];
    }

    /** @return array<string, string> */
    private function newExpenseProjectReferenceOptions(): array
    {
        return [
            'autonomous' => 'Spesa Autonoma',
            ...$this->proposal()->items()->where('source_type', 'project')->whereNull('project_id')->get()
                ->mapWithKeys(fn (ProposalItem $item): array => ['item:'.$item->proposal_item_id => 'Nuovo Progetto della Proposta · '.$item->proposal_item_id])
                ->all(),
        ];
    }

    /** @return array<int, string> */
    private function deferralProjectOptions(): array
    {
        if ($this->previousExercise() === null) {
            return [];
        }

        return $this->proposal()->items()
            ->where('source_type', 'project')
            ->whereNotNull('project_id')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ProposalItem $item): array => [$item->id => $item->project->title])
            ->all();
    }

    private function previousExercise(): ?Exercise
    {
        return Exercise::query()
            ->where('company_id', $this->proposal()->company_id)
            ->where('year', $this->proposal()->exercise->year - 1)
            ->first();
    }

    /** @return array<int, string> */
    private function proposalDeferralLineOptions(int $itemId): array
    {
        $item = $this->proposal()->items()->where('source_type', 'project')->whereNotNull('project_id')->find($itemId);
        $source = $this->previousExercise();
        if ($item === null || $source === null) {
            return [];
        }

        return ExpenseLine::query()
            ->select('expense_lines.*')
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.company_id', $this->proposal()->company_id)
            ->where('expenses.project_id', $item->project_id)
            ->where('expenses.exercise_id', $source->id)
            ->whereNull('expenses.reversed_at')
            ->where('expense_lines.type', 'estimate')
            ->whereNull('expense_lines.annulled_at')
            ->orderBy('expense_lines.id')
            ->get()
            ->mapWithKeys(fn (ExpenseLine $line): array => [$line->id => $line->expense->originKey().' · '.$line->expense->description.' · € '.$line->amount])
            ->all();
    }

    private function proposalDeferralSummary(Get $get): string
    {
        $item = $this->proposal()->items()->where('source_type', 'project')->whereNotNull('project_id')->find($get('item_id'));
        $source = $this->previousExercise();
        if ($source === null) {
            return 'Nessun Esercizio immediatamente precedente: il rinvio non è configurabile.';
        }
        if ($item === null || ! $item->project instanceof Project) {
            return 'Selezionare un Progetto per calcolare disponibilità e impatto.';
        }
        $project = $item->project;
        $current = $project->deferrals()->where('source_exercise_id', $source->id)->where('destination_exercise_id', $this->proposal()->exercise_id)->first();
        $totals = $project->annualTotals()[$source->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
        $availabilityAllocation = $current?->mode === ProjectDeferralMode::Reprogramming && $get('mode') === ProjectDeferralMode::Carryover->value
            ? Decimal::add($totals['allocation'], (string) $current->reprogrammed_amount)
            : $totals['allocation'];
        $residual = ProjectDeferralValues::residual($availabilityAllocation, $totals['actual']);
        $maximum = ProjectDeferralValues::maximumTransferable($availabilityAllocation, $totals['actual']);
        $reducible = Decimal::sum(ExpenseLine::query()
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.project_id', $project->id)
            ->where('expenses.exercise_id', $source->id)
            ->whereNull('expenses.reversed_at')
            ->where('expense_lines.type', 'estimate')
            ->whereNull('expense_lines.annulled_at')
            ->pluck('expense_lines.amount'));
        $selected = Decimal::sum(collect((array) $get('source_estimate_reductions'))->pluck('reduction_amount')->filter());
        $transitions = [
            ...ProposalPlanData::rows($item->result['transitions'] ?? null, 'transitions'),
            ...collect(ProposalPlanData::rows($item->result['planned_transitions'] ?? null, 'planned_transitions'))
                ->map(fn (array $transition): array => [...$transition, 'annulled_at' => null])
                ->all(),
        ];
        $resultingState = ProjectStateTimeline::stateAtDate(
            ProjectState::from((string) $item->result['initial_state']),
            (string) $item->result['initial_effective_date'],
            $transitions,
            $source->year.'-12-31',
        );
        $terminal = in_array($resultingState, [ProjectState::Closed, ProjectState::Cancelled], true);

        return $source->year.' → '.$this->proposal()->exercise->year
            .' · Allocato origine pre-operazione € '.$availabilityAllocation.' · Effettivo € '.$totals['actual']
            .' · Residuo € '.$residual.' · Disponibilità Massima € '.$maximum
            .' · Stime attive riducibili € '.$reducible.' · Riduzioni selezionate € '.$selected
            .' · Modalità viva '.($current?->mode->label() ?? ProjectDeferralMode::None->label())
            .($terminal ? ' · Blocco: Progetto terminale al 31 dicembre, è ammessa soltanto Nessuna.' : '')
            .(Decimal::compare($maximum, '0.00') === 0 ? ' · Riporto e Riprogrammazione non disponibili: massimo pari a zero.' : '')
            .' Le Stime origine restano invariate con Riporto; Riprogrammazione riduce le righe selezionate e genera nuove identità a destinazione; allocazioni indipendenti e Budget esistenti restano invariati.';
    }

    /** @return list<array<string, mixed>> */
    private function normalizeDeferralReductions(mixed $reductions): array
    {
        return collect(is_array($reductions) ? $reductions : [])->map(function (mixed $reduction): array {
            $row = is_array($reduction) ? $reduction : [];
            if (($row['destination_supplier_id'] ?? null) === 'none') {
                $row['destination_supplier_id'] = null;
            }

            return $row;
        })->values()->all();
    }

    private function deferralModeDisabled(string $mode, int $itemId): bool
    {
        if ($mode === ProjectDeferralMode::None->value) {
            return false;
        }
        $source = $this->previousExercise();
        $item = $this->proposal()->items()->where('source_type', 'project')->whereNotNull('project_id')->find($itemId);
        if ($source === null || ! $source->isOpen() || ! $this->proposal()->exercise->isOpen() || $item === null || ! $item->project instanceof Project) {
            return true;
        }
        $transitions = [
            ...ProposalPlanData::rows($item->result['transitions'] ?? null, 'transitions'),
            ...collect(ProposalPlanData::rows($item->result['planned_transitions'] ?? null, 'planned_transitions'))->map(fn (array $row): array => [...$row, 'annulled_at' => null])->all(),
        ];
        $state = ProjectStateTimeline::stateAtDate(
            ProjectState::from((string) $item->result['initial_state']),
            (string) $item->result['initial_effective_date'],
            $transitions,
            $source->year.'-12-31',
        );
        if (in_array($state, [ProjectState::Closed, ProjectState::Cancelled], true)) {
            return true;
        }
        $totals = $item->project->annualTotals()[$source->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
        $current = $item->project->deferrals()->where('source_exercise_id', $source->id)->where('destination_exercise_id', $this->proposal()->exercise_id)->first();
        if ($mode === ProjectDeferralMode::Reprogramming->value && $current?->mode === ProjectDeferralMode::Reprogramming) {
            return true;
        }
        $availabilityAllocation = $current?->mode === ProjectDeferralMode::Reprogramming && $mode === ProjectDeferralMode::Carryover->value
            ? Decimal::add($totals['allocation'], (string) $current->reprogrammed_amount)
            : $totals['allocation'];
        if (Decimal::compare(ProjectDeferralValues::maximumTransferable($availabilityAllocation, $totals['actual']), '0.00') === 0) {
            return true;
        }

        return $mode === ProjectDeferralMode::Reprogramming->value
            && ($this->proposalDeferralLineOptions($itemId) === [] || ! ExpensePlan::plannedProjectAcceptsExpense($item->result, $this->proposal()->exercise));
    }

    /** @return array{project_id: int|null, project_item_id: string|null} */
    private function parseExpenseProjectReference(string $reference): array
    {
        if ($reference === 'autonomous') {
            return ['project_id' => null, 'project_item_id' => null];
        } [$kind, $value] = explode(':', $reference, 2);

        return $kind === 'item' ? ['project_id' => null, 'project_item_id' => $value] : ['project_id' => (int) str($value)->after(':')->toString(), 'project_item_id' => null];
    }

    /** @return array<string, string> */
    private function contractReferenceOptions(): array
    {
        return [...$this->proposal()->items()->where('source_type', 'contract')->get()->mapWithKeys(fn (ProposalItem $item): array => ['item:'.$item->proposal_item_id => 'Proposta · '.$item->proposal_item_id])->all(), ...Contract::query()->where('company_id', $this->proposal()->company_id)->orderBy('title')->get()->mapWithKeys(fn (Contract $contract): array => ['origin:'.$contract->originKey() => 'Vivo · '.$contract->title])->all()];
    }

    /** @return array<string, string> */
    private function parseReference(string $reference, string $type): array
    {
        [$kind, $value] = explode(':', $reference, 2);

        return [$type.($kind === 'item' ? '_item_id' : '_origin_key') => $value];
    }
}
