<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\CreateContractCondition;
use App\Actions\Operations\SetContractConditionAnnulled;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractConditionsRelationManager extends RelationManager
{
    protected static string $relationship = 'conditions';

    protected static ?string $title = 'Condizioni economiche';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('status')->label('Stato')->state(fn (ContractCondition $record): string => $record->isAnnulled() ? 'Annullata' : 'Attiva')->badge(),
            TextColumn::make('amount')->label('Importo')->money('EUR', locale: 'it'),
            TextColumn::make('cycle')->label('Ciclo')->formatStateUsing(fn (string $state): string => ContractCycleType::from($state)->label()),
            TextColumn::make('attribution_mode')->label('Attribuzione')->formatStateUsing(fn (string $state): string => ContractAttributionMode::from($state)->label()),
            TextColumn::make('valid_from')->label('Valida dal')->date('d/m/Y'),
            TextColumn::make('valid_to')->label('Valida fino al')->date('d/m/Y')->placeholder('Senza termine'),
            TextColumn::make('creator.name')->label('Autore'),
            TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
        ])->headerActions([
            Action::make('createCondition')->label('Nuova condizione')->visible(fn (): bool => $this->canMutate())
                ->form($this->fields())
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $contract = $this->getOwnerRecord();
                    abort_unless($actor instanceof User && $contract instanceof Contract, 403);
                    $operationId = (string) $data['operation_id'];
                    unset($data['operation_id']);
                    app(CreateContractCondition::class)->execute($actor, $contract, $data, $operationId);
                    $contract->refresh();
                }),
        ])->recordActions([
            Action::make('annul')->label('Annulla')->color('warning')
                ->visible(fn (ContractCondition $record): bool => $this->canMutate() && ! $record->isAnnulled())
                ->form([
                    Textarea::make('reason')->label('Motivo')->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(function (ContractCondition $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(SetContractConditionAnnulled::class)->execute($actor, $record, true, (string) $data['reason'], (string) $data['operation_id']);
                    $this->getOwnerRecord()->refresh();
                }),
            Action::make('restore')->label('Ripristina')
                ->visible(fn (ContractCondition $record): bool => $this->canMutate() && $record->isAnnulled())
                ->form([
                    Textarea::make('reason')->label('Motivo')->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(function (ContractCondition $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(SetContractConditionAnnulled::class)->execute($actor, $record, false, (string) $data['reason'], (string) $data['operation_id']);
                    $this->getOwnerRecord()->refresh();
                }),
        ])->defaultSort('valid_from')->emptyStateHeading('Nessuna condizione');
    }

    /** @return array<int, mixed> */
    private function fields(): array
    {
        return [
            TextInput::make('amount')->label('Importo netto IVA')->numeric()->minValue(0)->required(),
            Select::make('cycle')->label('Ciclo')->options(ContractCycleType::options())->required(),
            Select::make('attribution_mode')->label('Attribuzione Stima')->options(ContractAttributionMode::options())->required(),
            DatePicker::make('valid_from')->label('Valida dal')->required(),
            DatePicker::make('valid_to')->label('Valida fino al'),
            Textarea::make('reason')->label('Nota')->nullable(),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    private function canMutate(): bool
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();

        return $actor instanceof User && $contract instanceof Contract && ! $contract->isArchived()
            && $actor->hasCapability($contract->company, Capability::ManageOperations);
    }
}
