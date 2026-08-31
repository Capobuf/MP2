<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\AnnulContractLifecycleFact;
use App\Actions\Operations\CancelContract;
use App\Actions\Operations\CeaseContract;
use App\Actions\Operations\ReactivateContract;
use App\Actions\Operations\ReplaceContractLifecycleFact;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Filament\Forms\DecimalInput;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ContractLifecycleRelationManager extends RelationManager
{
    protected static string $relationship = 'lifecycleFacts';

    protected static ?string $title = 'Ciclo di vita';

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
            TextColumn::make('declared_contractual_date')->label('Data contrattuale')->date('d/m/Y'),
            TextColumn::make('state_change_date')->label('Cambio stato dal')->date('d/m/Y')->placeholder('Stato invariato'),
            TextColumn::make('display_status')->label('Stato evento')->state(fn (ContractLifecycleFact $record): string => $record->annulledAt() !== null ? 'Annullato' : ($record->stateChangeDate()?->isFuture() ? 'Pianificato' : 'Efficace'))->badge(),
            TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
            TextColumn::make('creator.name')->label('Autore')->placeholder('Autore originale non disponibile'),
        ])->headerActions([
            Action::make('cease')->label('Cessa')->visible(fn (): bool => $this->canMutate())->form([
                DatePicker::make('date')->label('Ultimo giorno attivo')->required(), Textarea::make('reason')->label('Nota')->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                [$actor, $contract] = $this->context();
                app(CeaseContract::class)->execute($actor, $contract, (string) $data['date'], (string) $data['reason'], $contract->revision, (string) $data['operation_id']);
            }),
            Action::make('reactivate')->label('Riattiva')->visible(fn (): bool => $this->canMutate())->form([
                DatePicker::make('start_date')->label('Nuovo inizio')->required(), DatePicker::make('next_expiry_date')->label('Prossima scadenza'),
                DecimalInput::make('condition.amount')->label('Importo')->minValue(0)->required(),
                Select::make('condition.cycle')->label('Ciclo')->options(ContractCycleType::options())->required(),
                Select::make('condition.attribution_mode')->label('Attribuzione')->options(ContractAttributionMode::options())->required(),
                DatePicker::make('condition.valid_from')->label('Condizione valida dal')->required(), DatePicker::make('condition.valid_to')->label('Valida fino al'),
                Textarea::make('reason')->label('Nota')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                [$actor, $contract] = $this->context();
                $data['expected_revision'] = $contract->revision;
                app(ReactivateContract::class)->execute($actor, $contract, $data, (string) $data['operation_id']);
            }),
            Action::make('cancel')->label('Annulla prima dell’attivazione')->visible(fn (): bool => $this->canMutate())->form([
                Textarea::make('reason')->label('Motivo')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                [$actor, $contract] = $this->context();
                app(CancelContract::class)->execute($actor, $contract, (string) $data['reason'], $contract->revision, (string) $data['operation_id']);
            }),
        ])->recordActions([
            Action::make('annulFuture')->label('Annulla fatto futuro')->visible(fn (ContractLifecycleFact $record): bool => $this->canMutate() && $record->annulledAt() === null && $record->stateChangeDate()?->isFuture() === true)
                ->form([Textarea::make('reason')->label('Motivo')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])
                ->action(function (ContractLifecycleFact $record, array $data): void {
                    [$actor, $contract] = $this->context();
                    app(AnnulContractLifecycleFact::class)->execute($actor, $record, (string) $data['reason'], $contract->revision, (string) $data['operation_id']);
                }),
            Action::make('replaceFuture')->label('Sostituisci fatto futuro')->visible(fn (ContractLifecycleFact $record): bool => $this->canMutate() && $record->annulledAt() === null && $record->stateChangeDate()?->isFuture() === true)
                ->form([
                    Select::make('type')->label('Evento')->options(['activation' => 'Attivazione', 'cessation' => 'Cessazione', 'reactivation' => 'Riattivazione', 'cancellation' => 'Annullamento'])->required(),
                    DatePicker::make('declared_contractual_date')->label('Data contrattuale')->required(), Textarea::make('reason')->label('Nota'),
                    Textarea::make('replacement_reason')->label('Motivo sostituzione')->required(), Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(function (ContractLifecycleFact $record, array $data): void {
                    [$actor, $contract] = $this->context();
                    $data['expected_revision'] = $contract->revision;
                    app(ReplaceContractLifecycleFact::class)->execute($actor, $record, $data, (string) $data['operation_id']);
                }),
        ])->defaultSort('declared_contractual_date', 'desc');
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
