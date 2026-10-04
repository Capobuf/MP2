<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\AnnulContractLifecycleFact;
use App\Actions\Operations\CancelContract;
use App\Actions\Operations\CeaseContract;
use App\Actions\Operations\ReactivateContract;
use App\Actions\Operations\ReplaceContractLifecycleFact;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractImpactFingerprint;
use App\Domain\Contracts\ContractState;
use App\Domain\Proposals\ProposalPlanData;
use App\Filament\Forms\DateInput;
use App\Filament\Forms\DecimalInput;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ContractLifecycleRelationManager extends RelationManager
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $cancellationPreview = [];

    #[Locked]
    public int $lifecycleRevision = 0;

    protected static string $relationship = 'lifecycleFacts';

    protected static ?string $title = 'Ciclo di Vita';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type')->label('Evento')->formatStateUsing(fn (string $state): string => match ($state) {
                'activation' => 'Attivazione', 'cessation', 'expiry_cessation' => 'Cessazione',
                'reactivation' => 'Riattivazione', 'cancellation' => 'Annullamento', 'renewal' => 'Rinnovo',
                default => $state,
            }),
            TextColumn::make('declared_contractual_date')->label('Data Contrattuale')->date('d/m/Y'),
            TextColumn::make('state_change_date')->label('Cambio Stato dal')->date('d/m/Y')->placeholder('Stato invariato'),
            TextColumn::make('display_status')->label('Stato Evento')->state(fn (ContractLifecycleFact $record): string => $record->annulledAt() !== null ? 'Annullato' : ($record->stateChangeDate()?->isFuture() ? 'Pianificato' : 'Efficace'))->badge(),
            TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
            TextColumn::make('creator.name')->label('Autore')->placeholder('Autore originale non disponibile'),
        ])->headerActions([
            Action::make('cease')->label('Cessa')->visible(fn (): bool => $this->canMutate())->fillForm(function (): array {
                $this->lifecycleRevision = $this->context()[1]->revision;

                return ['operation_id' => (string) Str::uuid()];
            })->form([
                DateInput::make('date')->label('Ultimo Giorno Attivo')->required(), Textarea::make('reason')->label('Nota')->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                [$actor, $contract] = $this->context();
                app(CeaseContract::class)->execute($actor, $contract, (string) $data['date'], (string) $data['reason'], $this->lifecycleRevision, (string) $data['operation_id']);
            }),
            Action::make('reactivate')->label('Riattiva')->visible(fn (): bool => $this->canMutate())->fillForm(function (): array {
                $this->lifecycleRevision = $this->context()[1]->revision;

                return ['operation_id' => (string) Str::uuid()];
            })->form([
                DateInput::make('start_date')->label('Nuovo Inizio')->required(), DateInput::make('next_expiry_date')->label('Prossima Scadenza'),
                Checkbox::make('add_condition')->label('Aggiungi un canone ricorrente')->default(false)->live(),
                DecimalInput::make('condition.amount')->label('Importo')->minValue(0)->required()->visible(fn (Get $get): bool => (bool) $get('add_condition')),
                Select::make('condition.cycle')->label('Ciclo')->options(ContractCycleType::options())->required()->visible(fn (Get $get): bool => (bool) $get('add_condition')),
                Select::make('condition.attribution_mode')->label('Attribuzione')->options(ContractAttributionMode::options())->required()->visible(fn (Get $get): bool => (bool) $get('add_condition')),
                DateInput::make('condition.valid_from')->label('Condizione Valida dal')->required()->visible(fn (Get $get): bool => (bool) $get('add_condition')), DateInput::make('condition.valid_to')->label('Valida fino al')->visible(fn (Get $get): bool => (bool) $get('add_condition')),
                Textarea::make('reason')->label('Nota')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                [$actor, $contract] = $this->context();
                $data['expected_revision'] = $this->lifecycleRevision;
                if (! ($data['add_condition'] ?? false)) {
                    $data['condition'] = [];
                }
                app(ReactivateContract::class)->execute($actor, $contract, $data, (string) $data['operation_id']);
            }),
            Action::make('cancel')->label('Annulla Prima dell’Attivazione')->modalSubmitActionLabel('Conferma annullamento')
                ->visible(fn (): bool => $this->canMutate() && $this->context()[1]->stateAtDate(now($this->context()[1]->company->timezone)->toDateString()) === ContractState::Planned)
                ->fillForm(function (): array {
                    [$actor, $contract] = $this->context();
                    $this->cancellationPreview = app(CancelContract::class)->preview($actor, $contract);

                    return ['cost_choice' => 'keep', 'line_ids' => [], 'operation_id' => (string) Str::uuid()];
                })->form([
                    Radio::make('cost_choice')->label('Vuoi annullare anche i costi previsti?')
                        ->options(['keep' => 'Mantieni tutti', 'all' => 'Annulla tutti', 'selected' => 'Seleziona quelli da annullare'])
                        ->default('keep')->required()->live()
                        ->disableOptionWhen(fn (string $value): bool => ($value === 'all' && collect(ProposalPlanData::rows($this->cancellationPreview['candidates'] ?? null, 'candidates'))->contains('can_update', false)) || ($value === 'selected' && collect(ProposalPlanData::rows($this->cancellationPreview['candidates'] ?? null, 'candidates'))->where('can_update', true)->isEmpty()))
                        ->visible(fn (): bool => ($this->cancellationPreview['candidates'] ?? []) !== [])
                        ->helperText('Mantienili se pensi di sostenerli comunque. Le previsioni dei canoni saranno azzerate; gli Effettivi restano invariati.'),
                    CheckboxList::make('line_ids')->label('Costi aggiuntivi da annullare')
                        ->options(fn (): array => collect(ProposalPlanData::rows($this->cancellationPreview['candidates'] ?? null, 'candidates'))->mapWithKeys(fn (array $line): array => [
                            $line['line_id'] => $line['year'].' · '.$line['description'].' · '.($line['note'] ?: 'Riga '.$line['line_id']).' · '.Number::currency((float) $line['amount'], 'EUR', locale: 'it'),
                        ])->all())
                        ->disableOptionWhen(fn (string $value): bool => ! (collect(ProposalPlanData::rows($this->cancellationPreview['candidates'] ?? null, 'candidates'))->firstWhere('line_id', (int) $value)['can_update'] ?? false))
                        ->visible(fn (Get $get): bool => $get('cost_choice') === 'selected')->live(),
                    Placeholder::make('cost_summary')->hiddenLabel()->content(fn (Get $get) => view('filament.resources.contracts.components.cancel-review', [
                        'preview' => $this->cancellationPreview, 'selected' => $this->cancellationSelection($get('cost_choice'), $get('line_ids') ?? []),
                    ])),
                    Textarea::make('reason')->label('Motivo')->required(),
                    Hidden::make('operation_id'),
                ])->action(function (array $data): void {
                    [$actor, $contract] = $this->context();
                    app(CancelContract::class)->execute($actor, $contract, (string) $data['reason'], $this->cancellationPreview['revision'], (string) $data['operation_id'],
                        $this->cancellationSelection($data['cost_choice'] ?? 'keep', $data['line_ids'] ?? []), ContractImpactFingerprint::make($this->cancellationPreview));
                }),
        ])->recordActions([
            Action::make('annulFuture')->label('Annulla Fatto Futuro')->visible(fn (ContractLifecycleFact $record): bool => $this->canMutate() && $record->annulledAt() === null && $record->stateChangeDate()?->isFuture() === true)
                ->fillForm(function (): array {
                    $this->lifecycleRevision = $this->context()[1]->revision;

                    return [];
                })
                ->form([Textarea::make('reason')->label('Motivo')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])
                ->action(function (ContractLifecycleFact $record, array $data): void {
                    [$actor, $contract] = $this->context();
                    app(AnnulContractLifecycleFact::class)->execute($actor, $record, (string) $data['reason'], $this->lifecycleRevision, (string) $data['operation_id']);
                }),
            Action::make('replaceFuture')->label('Sostituisci Fatto Futuro')->visible(fn (ContractLifecycleFact $record): bool => $this->canMutate() && $record->annulledAt() === null && $record->stateChangeDate()?->isFuture() === true)
                ->fillForm(function (): array {
                    $this->lifecycleRevision = $this->context()[1]->revision;

                    return [];
                })
                ->form([
                    Select::make('type')->label('Evento')->options(['activation' => 'Attivazione', 'cessation' => 'Cessazione', 'reactivation' => 'Riattivazione', 'cancellation' => 'Annullamento'])->required(),
                    DateInput::make('declared_contractual_date')->label('Data Contrattuale')->required(), Textarea::make('reason')->label('Nota'),
                    Textarea::make('replacement_reason')->label('Motivo Sostituzione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(function (ContractLifecycleFact $record, array $data): void {
                    [$actor, $contract] = $this->context();
                    $data['expected_revision'] = $this->lifecycleRevision;
                    app(ReplaceContractLifecycleFact::class)->execute($actor, $record, $data, (string) $data['operation_id']);
                }),
        ])->defaultSort('declared_contractual_date', 'desc');
    }

    /** @param list<int|string> $ids
     * @return list<int>
     */
    private function cancellationSelection(?string $choice, array $ids): array
    {
        return match ($choice) {
            'all' => collect(ProposalPlanData::rows($this->cancellationPreview['candidates'] ?? null, 'candidates'))->pluck('line_id')->all(),
            'selected' => array_map('intval', $ids),
            default => [],
        };
    }

    /** @return array{User, Contract} */
    private function context(): array
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();
        abort_unless($actor instanceof User && $contract instanceof Contract, 403);

        return [$actor, $contract->refresh()];
    }

    private function canMutate(): bool
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();

        return $actor instanceof User && $contract instanceof Contract && ! $contract->isArchived()
            && $actor->can('update', $contract);
    }
}
