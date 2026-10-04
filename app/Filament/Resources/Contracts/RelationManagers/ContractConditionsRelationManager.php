<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\CreateContractCondition;
use App\Actions\Operations\SetContractConditionAnnulled;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Filament\Forms\DateInput;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractConditionsRelationManager extends RelationManager
{
    protected static string $relationship = 'conditions';

    protected static ?string $title = 'Condizioni Economiche';

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
            TextColumn::make('status')->label('Stato')->state(fn (ContractCondition $record): string => $record->isAnnulled() ? 'Annullata' : 'Valida')->badge()
                ->description(function (ContractCondition $record): ?string {
                    if ($record->isAnnulled()) {
                        return null;
                    }
                    $today = now($record->contract->company->timezone)->toDateString();

                    return $record->validFrom()->toDateString() > $today
                        ? 'Decorrenza futura'
                        : ($record->validTo() !== null && $record->validTo()->toDateString() < $today ? 'Periodo terminato' : 'Vigente oggi');
                }),
            TextColumn::make('amount')->label('Importo')->money('EUR', locale: 'it'),
            TextColumn::make('cycle')->label('Ciclo')->formatStateUsing(fn (string $state): string => ContractCycleType::from($state)->label()),
            TextColumn::make('attribution_mode')->label('Attribuzione')->formatStateUsing(fn (string $state): string => ContractAttributionMode::from($state)->label()),
            TextColumn::make('valid_from')->label('Valida dal')->date('d/m/Y'),
            TextColumn::make('valid_to')->label('Valida fino al')->date('d/m/Y')->placeholder('Senza termine'),
            TextColumn::make('creator.name')->label('Autore')->placeholder('Autore originale non disponibile'),
            TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
        ])->headerActions([
            Action::make('firstCondition')->label('Aggiungi Primo Canone')->visible(fn (): bool => $this->canMutate() && ! $this->hasConditions())
                ->url(fn (): string => ContractResource::getUrl('edit', ['record' => $this->getOwnerRecord()])),
            Action::make('createCondition')->label('Nuova Condizione')->visible(fn (): bool => $this->canMutate() && $this->hasConditions())
                ->form($this->fields())
                ->successNotificationTitle('Condizione Creata')
                ->action(function (array $data, Schema $schema): void {
                    $actor = auth()->user();
                    $contract = $this->getOwnerRecord();
                    abort_unless($actor instanceof User && $contract instanceof Contract, 403);
                    $operationId = (string) $data['operation_id'];
                    unset($data['operation_id']);
                    try {
                        app(CreateContractCondition::class)->execute($actor, $contract, $data, $operationId);
                    } catch (ValidationException $exception) {
                        $messages = [];
                        foreach ($exception->errors() as $field => $fieldMessages) {
                            $field = in_array($field, ['amount', 'cycle', 'attribution_mode', 'valid_from', 'valid_to', 'reason'], true)
                                ? $field
                                : 'valid_from';
                            $path = $schema->getStatePath().'.'.$field;
                            $messages[$path] = [...($messages[$path] ?? []), ...$fieldMessages];
                        }

                        throw ValidationException::withMessages($messages);
                    }
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
        ])->defaultSort('valid_from')
            ->emptyStateHeading('Nessuna Condizione')
            ->emptyStateDescription('Il Contratto può avere soltanto costi manuali. Aggiungi un canone quando esiste un accordo ricorrente.');
    }

    /** @return array<int, mixed> */
    private function fields(): array
    {
        return [
            DecimalInput::make('amount')->label('Importo Netto IVA')->minValue(0)->required(),
            Select::make('cycle')->label('Ciclo')->options(ContractCycleType::options())->required(),
            Select::make('attribution_mode')->label('Attribuzione Stima')->options(ContractAttributionMode::options())->required(),
            DateInput::make('valid_from')->label('Valida dal')->required(),
            DateInput::make('valid_to')->label('Valida fino al'),
            Textarea::make('reason')->label('Nota')->nullable(),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    private function hasConditions(): bool
    {
        $contract = $this->getOwnerRecord();

        return $contract instanceof Contract && $contract->conditions()->exists();
    }

    private function canMutate(): bool
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();

        return $actor instanceof User && $contract instanceof Contract && ! $contract->isArchived()
            && $actor->can('update', $contract);
    }
}
