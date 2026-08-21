<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\ChangeContractCondition;
use App\Actions\Operations\CorrectContractCondition;
use App\Actions\Operations\CreateContractCondition;
use App\Actions\Operations\SetContractConditionAnnulled;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractEconomicChangePlan;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            Action::make('changeAgreement')->label('Modifica accordo')
                ->visible(fn (ContractCondition $record): bool => $this->canMutate() && ! $record->isAnnulled())
                ->modalSubmitActionLabel('Conferma modifica economica')
                ->fillForm(fn (ContractCondition $record): array => [
                    'requested_date' => now($record->company->timezone)->toDateString(),
                    'amount' => $record->amount,
                    'cycle' => $record->cycle,
                    'attribution_mode' => $record->attribution_mode,
                ])->form($this->economicChangeFields())
                ->action(function (ContractCondition $record, array $data): void {
                    $actor = auth()->user();
                    $contract = $this->getOwnerRecord();
                    abort_unless($actor instanceof User && $contract instanceof Contract, 403);
                    $action = app(ChangeContractCondition::class);
                    $plan = $action->preview($contract, $record, $data);
                    $action->execute(
                        $actor,
                        $contract,
                        $record,
                        $data,
                        $plan->fingerprint(),
                        $plan->effectiveDate,
                        (string) $data['operation_id'],
                    );
                    $contract->refresh();
                }),
            Action::make('correctMaterialError')->label('Correggi errore materiale')
                ->visible(fn (ContractCondition $record): bool => $this->canMutate() && ! $record->isAnnulled())
                ->color('warning')->modalSubmitActionLabel('Conferma correzione')
                ->fillForm(fn (ContractCondition $record): array => [
                    'amount' => $record->amount,
                    'cycle' => $record->cycle,
                    'attribution_mode' => $record->attribution_mode,
                ])->form($this->materialCorrectionFields())
                ->action(function (ContractCondition $record, array $data): void {
                    $actor = auth()->user();
                    $contract = $this->getOwnerRecord();
                    abort_unless($actor instanceof User && $contract instanceof Contract, 403);
                    $action = app(CorrectContractCondition::class);
                    $plan = $action->preview($contract, $record, $data);
                    $action->execute($actor, $contract, $record, $data, $plan->fingerprint(), (string) $data['operation_id']);
                    $contract->refresh();
                }),
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
            ->emptyStateHeading('Nessuna condizione')
            ->emptyStateDescription('Ogni Contratto nasce con una prima condizione valida; verifica i filtri se non è visibile.');
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

    /** @return array<int, mixed> */
    private function economicChangeFields(): array
    {
        $invalidate = fn (Set $set): mixed => $set('effective_date_confirmed', false);

        return [
            DatePicker::make('requested_date')->label('Data richiesta')->required()->live()->afterStateUpdated($invalidate),
            TextInput::make('amount')->label('Nuovo importo netto IVA')->numeric()->minValue(0)->required()->live()->afterStateUpdated($invalidate),
            Select::make('cycle')->label('Nuovo ciclo')->options(ContractCycleType::options())->required()->live()->afterStateUpdated($invalidate),
            Select::make('attribution_mode')->label('Nuova attribuzione')->options(ContractAttributionMode::options())->required()->live()->afterStateUpdated($invalidate),
            Textarea::make('reason')->label('Nota accordo')->live()->afterStateUpdated($invalidate),
            Placeholder::make('impact_preview')->label('Anteprima economica')
                ->content(function (Get $get, ContractCondition $record): string {
                    $contract = $this->getOwnerRecord();
                    if (! $contract instanceof Contract || blank($get('requested_date')) || blank($get('amount')) || blank($get('cycle')) || blank($get('attribution_mode'))) {
                        return 'Completa i nuovi termini per calcolare l’anteprima.';
                    }
                    try {
                        $plan = app(ChangeContractCondition::class)->preview($contract, $record, [
                            'requested_date' => $get('requested_date'),
                            'amount' => $get('amount'),
                            'cycle' => $get('cycle'),
                            'attribution_mode' => $get('attribution_mode'),
                            'reason' => $get('reason'),
                        ]);
                    } catch (ValidationException $exception) {
                        return (string) (collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.');
                    }

                    return $this->formatEconomicImpact($plan);
                }),
            Checkbox::make('effective_date_confirmed')
                ->label('Confermo la decorrenza effettiva mostrata e che non viene applicato alcun prorata')
                ->accepted()->required(),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** @return array<int, mixed> */
    private function materialCorrectionFields(): array
    {
        $invalidate = fn (Set $set): mixed => $set('impact_confirmed', false);

        return [
            TextInput::make('amount')->label('Importo corretto')->numeric()->minValue(0)->required()->live()->afterStateUpdated($invalidate),
            Select::make('cycle')->label('Ciclo corretto')->options(ContractCycleType::options())->required()->live()->afterStateUpdated($invalidate),
            Select::make('attribution_mode')->label('Attribuzione corretta')->options(ContractAttributionMode::options())->required()->live()->afterStateUpdated($invalidate),
            Textarea::make('reason')->label('Motivo della correzione')->required()->live()->afterStateUpdated($invalidate),
            Checkbox::make('declared_input_error')->label('Dichiaro che il valore originario era un errore di inserimento')->accepted()->required()->live()->afterStateUpdated($invalidate),
            Checkbox::make('declared_no_new_agreement')->label('Dichiaro che non è iniziato un nuovo accordo')->accepted()->required()->live()->afterStateUpdated($invalidate),
            Placeholder::make('impact_preview')->label('Anteprima correzione')
                ->content(function (Get $get, ContractCondition $record): string {
                    $contract = $this->getOwnerRecord();
                    if (! $contract instanceof Contract || blank($get('amount')) || blank($get('cycle')) || blank($get('attribution_mode')) || blank($get('reason'))) {
                        return 'Completa la dichiarazione per calcolare l’anteprima.';
                    }
                    try {
                        $plan = app(CorrectContractCondition::class)->preview($contract, $record, [
                            'amount' => $get('amount'),
                            'cycle' => $get('cycle'),
                            'attribution_mode' => $get('attribution_mode'),
                            'reason' => $get('reason'),
                            'declared_input_error' => $get('declared_input_error'),
                            'declared_no_new_agreement' => $get('declared_no_new_agreement'),
                        ]);
                    } catch (ValidationException $exception) {
                        return (string) (collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.');
                    }

                    return $this->formatEconomicImpact($plan);
                }),
            Checkbox::make('impact_confirmed')->label('Confermo l’impatto completo mostrato')->accepted()->required(),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    private function formatEconomicImpact(ContractEconomicChangePlan $plan): string
    {
        $rows = [];
        if ($plan->requestedDate !== null) {
            $rows[] = "Richiesta {$plan->requestedDate}; minimo {$plan->minimumDate}; effettiva {$plan->effectiveDate}.";
        }
        $rows[] = $plan->delayReason;
        $rows[] = 'Prorata applicato: no.';
        $rows[] = "Termini: {$plan->oldTerms['amount']} / {$plan->oldTerms['cycle']} / {$plan->oldTerms['attribution_mode']} → {$plan->newTerms['amount']} / {$plan->newTerms['cycle']} / {$plan->newTerms['attribution_mode']}.";
        foreach ($plan->exerciseImpacts as $impact) {
            $rows[] = "Esercizio {$impact['year']}: {$impact['allocation_before']} → {$impact['allocation_after']} ({$impact['allocation_delta']}).";
        }

        return implode(' ', $rows);
    }
}
