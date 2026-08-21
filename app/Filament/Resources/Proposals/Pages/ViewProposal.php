<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Operations\UploadAttachment;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CopyExpenseIntoProposal;
use App\Actions\Proposals\IncludeProposalSource;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProposalRelation;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Contracts\ContractState;
use App\Domain\Projects\ProjectState;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalSourceType;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')->label('Timeline della Proposta')->url(fn (): string => CompanyAudit::getUrl(['tenant' => $this->proposal()->company, 'proposal' => $this->proposal()->id])),
            Action::make('viewBudget')->label('Apri Budget v1')->url(fn (): string => BudgetResource::getUrl('view', ['record' => $this->proposal()->budget()->sole()], tenant: $this->proposal()->company))->visible(fn (): bool => $this->proposal()->budget()->exists()),
            Action::make('approveBudget')->label('Approva e crea Budget v1')->color('success')->requiresConfirmation()->modalHeading('Approva Proposta e crea Budget v1')->modalDescription(fn (): string => 'La conferma rivalida e applica atomicamente il piano. '.$this->approvalSummary())->modalSubmitActionLabel('Approva e crea Budget v1')->visible(fn (): bool => $this->canApprove())->disabled(fn (): bool => ! $this->approvalReady())->tooltip(fn (): ?string => $this->approvalReady() ? null : 'Risolvere tutti i blocchi di verifica prima dell’approvazione.')->form([
                Placeholder::make('final_impact')->label('Impatto finale da approvare')->content(fn (): string => $this->approvalSummary()), TextInput::make('external_subject')->label('Soggetto approvante esterno')->maxLength(255), TextInput::make('external_venue')->label('Sede o verbale')->maxLength(255), Textarea::make('reason')->label('Nota di approvazione'), FileUpload::make('new_evidence')->label('Nuova evidenza privata')->storeFiles(false), Select::make('attachment_ids')->label('Evidenze già presenti')->multiple()->options(fn (): array => Attachment::query()->where('company_id', $this->proposal()->company_id)->whereNull('detached_at')->orderBy('original_name')->pluck('original_name', 'id')->all()), Hidden::make('evidence_operation_id')->default(fn (): string => $this->evidenceOperationId), Hidden::make('operation_id')->default(fn (): string => $this->approvalOperationId),
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
            Action::make('reviewReadiness')->label('Ricalcola verifiche')->visible(fn (): bool => $this->canPlan())->form([Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])->action(function (array $data): void {
                $this->record = app(ReviewProposalReadiness::class)->execute($this->actor(), $this->proposal(), $data['operation_id']);
                $this->refreshProposal('Verifiche ricalcolate');
            }),
            ActionGroup::make([
                Action::make('includeClosedProject')->label('Seleziona Progetto da riaprire')->visible(fn (): bool => $this->canPlan())->form([Select::make('source_id')->label('Progetto Chiuso o Cancellato')->options(fn (): array => $this->eligibleProjectOptions())->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(IncludeProposalSource::class)->execute($this->actor(), $this->proposal(), ProposalSourceType::Project, (int) $data['source_id'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Progetto selezionato: pianificare ora la riapertura');
                }),
                Action::make('includeTerminatedContract')->label('Seleziona Contratto da riattivare')->visible(fn (): bool => $this->canPlan())->form([Select::make('source_id')->label('Contratto Cessato o Annullato')->options(fn (): array => $this->eligibleContractOptions())->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(IncludeProposalSource::class)->execute($this->actor(), $this->proposal(), ProposalSourceType::Contract, (int) $data['source_id'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Contratto selezionato: pianificare ora condizione e riattivazione');
                }),
                Action::make('createPlannedExpense')->label('Nuova Spesa pianificata')->visible(fn (): bool => $this->canPlan())->form([
                    TextInput::make('description')->label('Descrizione')->required()->maxLength(255), Textarea::make('notes')->label('Note'),
                    Select::make('project_reference')->label('Progetto di destinazione')->options(fn (): array => $this->expenseProjectReferenceOptions())->default('autonomous')->required(),
                    Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->placeholder('Nessun Fornitore'),
                    Select::make('cost_center_id')->label('Centro di Costo diretto (solo autonoma)')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificata'),
                    Repeater::make('estimate_lines')->label('Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->modalDescription('Per riposizionare piano residuo, ridurre prima le Stime originarie e creare qui una Spesa distinta: nessun matching con gli Effettivi.')->action(function (array $data): void {
                    $actor = $this->actor();
                    app(PlanExpense::class)->create($actor, $this->proposal(), ['description' => $data['description'], 'notes' => $data['notes'] ?? null, 'exercise_id' => $this->proposal()->exercise_id, 'supplier_id' => filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null, 'cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null, ...$this->parseExpenseProjectReference($data['project_reference']), 'estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines'])], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Spesa pianificata aggiunta');
                }),
                Action::make('planExpenseEstimates')->label('Modifica Stime Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(),
                    Repeater::make('estimate_lines')->label('Sostituzione completa Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines'])], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Stime pianificate aggiornate');
                }),
                Action::make('planExpenseOwner')->label('Sposta piano Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa priva di Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(),
                    Select::make('exercise_id')->label('Esercizio Aperto')->options(fn (): array => Exercise::query()->where('company_id', $this->proposal()->company_id)->open()->orderBy('year')->pluck('year', 'id')->all())->default(fn (): int => $this->proposal()->exercise_id)->required(),
                    Select::make('project_reference')->label('Contenitore piano')->options(fn (): array => $this->expenseProjectReferenceOptions())->default('autonomous')->required(),
                    Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->modalDescription('Muove soltanto una Spesa priva di Effettivi tra autonomia e Progetti. Il cambio anno di una Spesa di Progetto è Riprogrammazione S8 e resta indisponibile.')->action(function (array $data): void {
                    $item = $this->expenseItem((int) $data['item_id']);
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetExpenseOwner, ['exercise_id' => (int) $data['exercise_id'], ...$this->parseExpenseProjectReference($data['project_reference'])], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Contenitore del piano aggiornato');
                }),
                Action::make('planExpenseSupplier')->label('Cambia Fornitore piano Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa autonoma o di Progetto senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->placeholder('Nessun Fornitore'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::SetExpenseSupplier, ['supplier_id' => filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Fornitore del piano aggiornato');
                }),
                Action::make('planExpenseCostCenter')->label('Cambia Centro di Costo piano Spesa')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Spesa autonoma senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cost_center_id')->label('Centro di Costo diretto')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificata'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::SetExpenseCostCenter, ['cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Centro di Costo del piano aggiornato');
                }),
                Action::make('reversePlannedExpense')->label('Storna Spesa nel piano')->visible(fn (): bool => $this->canPlan())->requiresConfirmation()->form([Select::make('item_id')->label('Spesa priva di Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Textarea::make('reason')->label('Motivazione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::ReverseExpense, ['reason' => $data['reason']], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Storno pianificato');
                }),
                Action::make('restorePlannedExpense')->label('Ripristina Spesa nel piano')->visible(fn (): bool => $this->canPlan())->requiresConfirmation()->form([Select::make('item_id')->label('Spesa Stornata priva di Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->pluck('proposal_item_id', 'id')->all())->required(), Textarea::make('reason')->label('Motivazione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision)])->action(function (array $data): void {
                    app(PlanExpense::class)->execute($this->actor(), $this->proposal(), $this->expenseItem((int) $data['item_id']), ProposalActionType::RestoreExpense, ['reason' => $data['reason']], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Ripristino pianificato');
                }),
                Action::make('copyExpense')->label('Copia Spesa autonoma')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('source_expense_id')->label('Spesa autonoma di altro Esercizio Aperto')->options(fn (): array => Expense::query()->where('company_id', $this->proposal()->company_id)->where('exercise_id', '<>', $this->proposal()->exercise_id)->whereNull('project_id')->whereNull('contract_id')->whereNull('reversed_at')->whereHas('exercise', fn ($query) => $query->where('status', 'open'))->orderBy('description')->pluck('description', 'id')->all())->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(CopyExpenseIntoProposal::class)->execute($this->actor(), $this->proposal(), Expense::query()->findOrFail($data['source_expense_id']), $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Spesa copiata con nuova identità e lineage');
                }),
                Action::make('createPlannedProject')->label('Nuovo Progetto pianificato')->visible(fn (): bool => $this->canPlan())->form([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255), Textarea::make('description')->label('Descrizione'), Textarea::make('notes')->label('Note'),
                    Hidden::make('initial_state')->default('planned'), DatePicker::make('initial_effective_date')->label('Efficace dal')->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanProject::class)->create($this->actor(), $this->proposal(), ['title' => $data['title'], 'description' => $data['description'] ?? null, 'notes' => $data['notes'] ?? null, 'initial_state' => $data['initial_state'], 'initial_effective_date' => $data['initial_effective_date'], 'exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => null], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Progetto pianificato aggiunto');
                }),
                Action::make('planProjectTransition')->label('Pianifica stato Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('from_state')->label('Da')->options(['planned' => 'Pianificato', 'open' => 'Aperto', 'closed' => 'Chiuso', 'cancelled' => 'Cancellato'])->required(), Select::make('to_state')->label('A')->options(['planned' => 'Pianificato', 'open' => 'Aperto', 'closed' => 'Chiuso', 'cancelled' => 'Cancellato'])->required(), DatePicker::make('effective_date')->label('Data efficacia')->required(), Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanProjectTransition, ['from_state' => $data['from_state'], 'to_state' => $data['to_state'], 'effective_date' => $data['effective_date'], 'reason' => $data['reason'] ?? null], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Stato Progetto pianificato');
                }),
                Action::make('planProjectChildExpenses')->label('Associa Spese pianificate al Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(),
                    Select::make('child_item_ids')->label('Nuove Spese della stessa Proposta')->multiple()->options(fn (): array => $this->proposal()->items()->where('source_type', 'expense')->whereNull('expense_id')->pluck('proposal_item_id', 'proposal_item_id')->all())->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanProjectChildExpenses, ['child_item_ids' => array_values($data['child_item_ids']), 'existing_expenses' => []], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Spese pianificate associate al Progetto');
                }),
                Action::make('planProjectExpenseEstimates')->label('Modifica Stime figlie Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(),
                    Select::make('expense_id')->label('Spesa figlia esistente')->options(fn (): array => Expense::query()->where('company_id', $this->proposal()->company_id)->where('exercise_id', $this->proposal()->exercise_id)->whereNotNull('project_id')->whereNull('reversed_at')->orderBy('description')->pluck('description', 'id')->all())->required(),
                    Repeater::make('estimate_lines')->label('Sostituzione completa Righe Stima')->schema($this->estimateLineSchema())->defaultItems(1)->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanProjectChildExpenses, ['child_item_ids' => [], 'existing_expenses' => [['expense_id' => (int) $data['expense_id'], 'estimate_lines' => $this->normalizeEstimateLines($data['estimate_lines'])]]], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Stime figlie del Progetto aggiornate');
                }),
                Action::make('planProjectCostCenter')->label('Cambia Centro di Costo Progetto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Progetto senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'project')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cost_center_id')->label('Centro di Costo annuale')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificato'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->where('source_type', 'project')->findOrFail($data['item_id']);
                    app(PlanProject::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetProjectCostCenter, ['exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Centro di Costo del Progetto aggiornato');
                }),
                Action::make('createPlannedContract')->label('Nuovo Contratto pianificato')->visible(fn (): bool => $this->canPlan())->form([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255), Select::make('supplier_id')->label('Fornitore')->options(fn (): array => Supplier::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())->required(), DatePicker::make('contractual_start_date')->label('Inizio contrattuale')->required(), DatePicker::make('next_expiry_date')->label('Prossima scadenza'), Toggle::make('automatic_renewal')->label('Rinnovo automatico')->default(false), TextInput::make('renewal_duration_months')->label('Durata rinnovo (mesi)')->integer()->minValue(1), TextInput::make('notice_days')->label('Preavviso (giorni)')->integer()->minValue(0)->default(0), Textarea::make('notes')->label('Note'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanContract::class)->create($this->actor(), $this->proposal(), ['title' => $data['title'], 'notes' => $data['notes'] ?? null, 'supplier_id' => (int) $data['supplier_id'], 'contractual_start_date' => $data['contractual_start_date'], 'next_expiry_date' => $data['next_expiry_date'] ?? null, 'automatic_renewal' => (bool) ($data['automatic_renewal'] ?? false), 'renewal_duration_months' => filled($data['renewal_duration_months'] ?? null) ? (int) $data['renewal_duration_months'] : null, 'notice_days' => (int) ($data['notice_days'] ?? 0), 'exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => null], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Contratto pianificato aggiunto');
                }),
                Action::make('addContractCondition')->label('Aggiungi condizione Contratto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cycle')->label('Ciclo')->options(['monthly' => 'Mensile', 'quarterly' => 'Trimestrale', 'semiannual' => 'Semestrale', 'annual' => 'Annuale'])->required(), Select::make('attribution_mode')->label('Attribuzione')->options(['cycle_start' => 'Inizio ciclo', 'cycle_end' => 'Fine ciclo'])->required(), TextInput::make('amount')->label('Importo netto IVA')->numeric()->minValue(0)->required(), DatePicker::make('valid_from')->label('Valida dal')->required(), DatePicker::make('valid_to')->label('Valida fino al'), Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::AddContractCondition, ['cycle' => $data['cycle'], 'attribution_mode' => $data['attribution_mode'], 'amount' => number_format((float) $data['amount'], 2, '.', ''), 'valid_from' => $data['valid_from'], 'valid_to' => $data['valid_to'] ?? null, 'reason' => $data['reason'] ?? null], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Condizione contrattuale pianificata');
                }),
                Action::make('changeContractEconomics')->label('Modifica economica Contratto')->visible(fn (): bool => $this->canPlan())->modalDescription('Sono mostrate data richiesta, data minima e data efficace. Prorata applicato: no.')->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), TextInput::make('condition_id')->label('ID condizione')->integer()->required(), TextInput::make('amount')->label('Nuovo importo netto IVA')->numeric()->minValue(0)->required(), Select::make('cycle')->label('Nuovo ciclo')->options(['monthly' => 'Mensile', 'quarterly' => 'Trimestrale', 'semiannual' => 'Semestrale', 'annual' => 'Annuale'])->required(), Select::make('attribution_mode')->label('Nuova attribuzione')->options(['cycle_start' => 'Inizio ciclo', 'cycle_end' => 'Fine ciclo'])->required(), DatePicker::make('requested_date')->label('Data richiesta')->required(), DatePicker::make('confirmed_effective_date')->label('Data efficace applicabile confermata')->required(), Textarea::make('reason')->label('Motivazione'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->modalDescription('Il server ricalcola Data minima ed effettiva dal confine del ciclo. Se la conferma non coincide, mostra la data esatta e non salva. Prorata applicato: no.')->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::ChangeContractEconomics, ['condition_id' => (int) $data['condition_id'], 'amount' => number_format((float) $data['amount'], 2, '.', ''), 'cycle' => $data['cycle'], 'attribution_mode' => $data['attribution_mode'], 'requested_date' => $data['requested_date'], 'confirmed_effective_date' => $data['confirmed_effective_date'], 'reason' => $data['reason'] ?? null], $data['reason'] ?? null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Modifica economica pianificata');
                }),
                Action::make('planContractLifecycle')->label('Pianifica cessazione o riattivazione')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('type')->label('Evento')->options(['cessation' => 'Cessazione', 'reactivation' => 'Riattivazione'])->required(), DatePicker::make('declared_contractual_date')->label('Ultimo giorno Attivo / nuova data di inizio')->required(), DatePicker::make('effective_date')->label('Data efficace')->required(), DatePicker::make('next_expiry_date')->label('Nuova prossima scadenza (riattivazione)'), Textarea::make('reason')->label('Motivazione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::PlanContractLifecycle, ['type' => $data['type'], 'declared_contractual_date' => $data['declared_contractual_date'], 'effective_date' => $data['effective_date'], 'next_expiry_date' => $data['next_expiry_date'] ?? null, 'reason' => $data['reason']], $data['reason'], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Evento contrattuale pianificato');
                }),
                Action::make('planContractRenewal')->label('Modifica rinnovo e scadenza')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), DatePicker::make('effective_from')->label('Configurazione efficace dal')->required(), DatePicker::make('expiry_anchor_date')->label('Prossima scadenza'), Toggle::make('automatic_renewal')->label('Rinnovo automatico')->default(false), TextInput::make('renewal_duration_months')->label('Durata rinnovo (mesi)')->integer()->minValue(1), TextInput::make('notice_days')->label('Preavviso (giorni)')->integer()->minValue(0), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetContractRenewal, ['effective_from' => $data['effective_from'], 'expiry_anchor_date' => $data['expiry_anchor_date'] ?? null, 'automatic_renewal' => (bool) ($data['automatic_renewal'] ?? false), 'renewal_duration_months' => filled($data['renewal_duration_months'] ?? null) ? (int) $data['renewal_duration_months'] : null, 'notice_days' => filled($data['notice_days'] ?? null) ? (int) $data['notice_days'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Rinnovo contrattuale pianificato');
                }),
                Action::make('planContractCostCenter')->label('Cambia Centro di Costo Contratto')->visible(fn (): bool => $this->canPlan())->form([
                    Select::make('item_id')->label('Contratto senza Effettivi')->options(fn (): array => $this->proposal()->items()->where('source_type', 'contract')->pluck('proposal_item_id', 'id')->all())->required(), Select::make('cost_center_id')->label('Centro di Costo annuale')->options(fn (): array => CostCenter::query()->where('company_id', $this->proposal()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Non classificato'), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    $item = ProposalItem::query()->where('proposal_id', $this->proposal()->id)->findOrFail($data['item_id']);
                    app(PlanContract::class)->execute($this->actor(), $this->proposal(), $item, ProposalActionType::SetContractCostCenter, ['exercise_id' => $this->proposal()->exercise_id, 'cost_center_id' => filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null], null, $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Centro di Costo del Contratto aggiornato');
                }),
                Action::make('linkProjectContract')->label('Collega Progetto e Contratto')->visible(fn (): bool => $this->canPlan())->modalDescription('Collegamento informativo “Collegato a”, senza effetto economico.')->form([
                    Select::make('project_reference')->label('Progetto')->options(fn (): array => $this->projectReferenceOptions())->required(), Select::make('contract_reference')->label('Contratto')->options(fn (): array => $this->contractReferenceOptions())->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('proposal_revision')->default(fn (): int => $this->proposal()->revision),
                ])->action(function (array $data): void {
                    app(PlanProposalRelation::class)->execute($this->actor(), $this->proposal(), [...$this->parseReference($data['project_reference'], 'project'), ...$this->parseReference($data['contract_reference'], 'contract')], $data['operation_id'], (int) $data['proposal_revision']);
                    $this->refreshProposal('Progetto e Contratto collegati');
                }),
            ])->label('Azioni di piano')->button(),
        ];
    }

    /** @return array<int, mixed> */
    private function estimateLineSchema(): array
    {
        return [Hidden::make('proposal_line_id')->default(fn (): string => (string) Str::uuid()), Hidden::make('line_id')->default(null), TextInput::make('amount')->label('Importo netto IVA')->numeric()->minValue(0)->required(), Textarea::make('note')->label('Nota'), Toggle::make('annulled')->label('Annullata')->default(false)];
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

    private function canApprove(): bool
    {
        return $this->proposal()->status->value === 'draft' && auth()->user()?->can('approve', $this->proposal()) === true;
    }

    private function approvalReady(): bool
    {
        return app(ProposalReadiness::class)->assessProposal($this->proposal())['ready'];
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
        $this->record = $this->proposal()->refresh()->load(['exercise', 'creator', 'items', 'actions']);
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
        return ['autonomous' => 'Spesa autonoma', ...$this->projectReferenceOptions()];
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
