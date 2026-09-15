<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\ChangeContractCondition;
use App\Actions\Operations\CorrectContractCondition;
use App\Actions\Operations\CreateContractCondition;
use App\Actions\Operations\SetContractConditionAnnulled;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractEconomicChangePlan;
use App\Domain\Expenses\Decimal;
use App\Filament\Forms\DateInput;
use App\Filament\Forms\DecimalInput;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
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
            TextColumn::make('status')->label('Stato')->state(fn (ContractCondition $record): string => $record->isAnnulled() ? 'Annullata' : 'Attiva')->badge(),
            TextColumn::make('amount')->label('Importo')->money('EUR', locale: 'it'),
            TextColumn::make('cycle')->label('Ciclo')->formatStateUsing(fn (string $state): string => ContractCycleType::from($state)->label()),
            TextColumn::make('attribution_mode')->label('Attribuzione')->formatStateUsing(fn (string $state): string => ContractAttributionMode::from($state)->label()),
            TextColumn::make('valid_from')->label('Valida dal')->date('d/m/Y'),
            TextColumn::make('valid_to')->label('Valida fino al')->date('d/m/Y')->placeholder('Senza termine'),
            TextColumn::make('creator.name')->label('Autore')->placeholder('Autore originale non disponibile'),
            TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
        ])->headerActions([
            Action::make('createCondition')->label('Nuova Condizione')->visible(fn (): bool => $this->canMutate())
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
            Action::make('changeAgreement')->label('Modifica Accordo')
                ->visible(fn (ContractCondition $record): bool => $this->canMutate() && ! $record->isAnnulled())
                ->modalDescription('Registra una modifica reale dell’accordo. La decorrenza applicabile è calcolata senza prorata e deve essere confermata esplicitamente.')
                ->modalWidth(Width::FourExtraLarge)
                ->modalSubmitActionLabel('Conferma Modifica Economica')
                ->successNotificationTitle('Modifica economica registrata')
                ->fillForm(fn (ContractCondition $record): array => [
                    'requested_date' => now($record->company->timezone)->toDateString(),
                    'amount' => $record->amount,
                    'cycle' => $record->cycle,
                    'attribution_mode' => $record->attribution_mode,
                    'operation_id' => (string) Str::uuid(),
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
            Action::make('correctMaterialError')->label('Correggi Errore Materiale')
                ->visible(fn (ContractCondition $record): bool => $this->canMutate() && ! $record->isAnnulled())
                ->color('warning')
                ->modalDescription('Corregge il dato originario senza rappresentare un nuovo accordo. Sono richieste le dichiarazioni canoniche e la conferma dell’impatto completo.')
                ->modalWidth(Width::FourExtraLarge)
                ->modalSubmitActionLabel('Conferma Correzione')
                ->successNotificationTitle('Errore materiale corretto')
                ->fillForm(fn (ContractCondition $record): array => [
                    'amount' => $record->amount,
                    'cycle' => $record->cycle,
                    'attribution_mode' => $record->attribution_mode,
                    'operation_id' => (string) Str::uuid(),
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
            ->emptyStateHeading('Nessuna Condizione')
            ->emptyStateDescription('Ogni Contratto nasce con una prima condizione valida; verifica i filtri se non è visibile.');
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

    private function canMutate(): bool
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();

        return $actor instanceof User && $contract instanceof Contract && ! $contract->isArchived()
            && $actor->can('update', $contract);
    }

    /** @return array<int, mixed> */
    private function economicChangeFields(): array
    {
        $invalidate = fn (Set $set): mixed => $set('effective_date_confirmed', false);

        return [
            Section::make('Nuovi Termini dell’Accordo')
                ->description('La Data richiesta può essere rinviata al primo confine di ciclo applicabile, come mostrato nell’anteprima.')
                ->schema([
                    DateInput::make('requested_date')->label('Data Richiesta')->required()->live()->afterStateUpdated($invalidate),
                    DecimalInput::make('amount')->label('Nuovo Importo Netto IVA')->minValue(0)->required()->live()->afterStateUpdated($invalidate),
                    Select::make('cycle')->label('Nuovo Ciclo')->options(ContractCycleType::options())->required()->live()->afterStateUpdated($invalidate),
                    Select::make('attribution_mode')->label('Nuova Attribuzione')->options(ContractAttributionMode::options())->required()->live()->afterStateUpdated($invalidate),
                    Textarea::make('reason')->label('Nota Accordo')->live()->afterStateUpdated($invalidate)->columnSpanFull(),
                ])->columns(2),
            Section::make('Anteprima Canonica')
                ->schema([
                    Placeholder::make('impact_preview')->hiddenLabel()
                        ->content(function (Get $get, ContractCondition $record): View {
                            $contract = $this->getOwnerRecord();
                            if (! $contract instanceof Contract || blank($get('requested_date')) || blank($get('amount')) || blank($get('cycle')) || blank($get('attribution_mode'))) {
                                return $this->economicImpactPreview(error: 'Completa i nuovi termini per calcolare l’anteprima.');
                            }
                            try {
                                $plan = app(ChangeContractCondition::class)->preview($contract, $record, [
                                    'requested_date' => DateInput::toIso($get('requested_date')),
                                    'amount' => Decimal::normalizeInput($get('amount')),
                                    'cycle' => $get('cycle'),
                                    'attribution_mode' => $get('attribution_mode'),
                                    'reason' => $get('reason'),
                                ]);
                            } catch (ValidationException $exception) {
                                return $this->economicImpactPreview(
                                    error: (string) (collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.'),
                                );
                            }

                            return $this->economicImpactPreview($plan);
                        }),
                ]),
            Section::make('Conferma Obbligatoria')
                ->description('La modifica non può essere registrata senza questa conferma esplicita.')
                ->schema([
                    Checkbox::make('effective_date_confirmed')
                        ->label('Confermo la decorrenza effettiva mostrata e che non viene applicato alcun prorata')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Conferma esplicitamente la decorrenza effettiva e l’assenza di prorata per proseguire.'])
                        ->markAsRequired(),
                ]),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** @return array<int, mixed> */
    private function materialCorrectionFields(): array
    {
        $invalidate = fn (Set $set): mixed => $set('impact_confirmed', false);

        return [
            Section::make('Dato Originario Corretto')
                ->description('Questi valori sostituiscono quelli inseriti in modo errato mantenendo la decorrenza originaria.')
                ->schema([
                    DecimalInput::make('amount')->label('Importo Corretto')->minValue(0)->required()->live()->afterStateUpdated($invalidate),
                    Select::make('cycle')->label('Ciclo Corretto')->options(ContractCycleType::options())->required()->live()->afterStateUpdated($invalidate),
                    Select::make('attribution_mode')->label('Attribuzione Corretta')->options(ContractAttributionMode::options())->required()->live()->afterStateUpdated($invalidate),
                    Textarea::make('reason')->label('Motivo della Correzione')
                        ->helperText('Obbligatorio: descrivi l’errore materiale che stai correggendo.')
                        ->required()->live()->afterStateUpdated($invalidate)->columnSpanFull(),
                ])->columns(2),
            Section::make('Dichiarazioni Obbligatorie')
                ->description('La correzione materiale è ammessa soltanto se entrambe le dichiarazioni sono vere.')
                ->schema([
                    Checkbox::make('declared_input_error')
                        ->label('Dichiaro che il valore originario era un errore di inserimento')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Dichiara che il valore originario era un errore di inserimento per proseguire.'])
                        ->markAsRequired()
                        ->live()->afterStateUpdated($invalidate),
                    Checkbox::make('declared_no_new_agreement')
                        ->label('Dichiaro che non è iniziato un nuovo accordo')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Dichiara che la correzione non rappresenta un nuovo accordo per proseguire.'])
                        ->markAsRequired()
                        ->live()->afterStateUpdated($invalidate),
                ]),
            Section::make('Anteprima Canonica')
                ->schema([
                    Placeholder::make('impact_preview')->hiddenLabel()
                        ->content(function (Get $get, ContractCondition $record): View {
                            $contract = $this->getOwnerRecord();
                            if (! $contract instanceof Contract || blank($get('amount')) || blank($get('cycle')) || blank($get('attribution_mode')) || blank($get('reason'))) {
                                return $this->economicImpactPreview(error: 'Completa i valori corretti e il motivo per calcolare l’anteprima.');
                            }
                            if ($get('declared_input_error') !== true || $get('declared_no_new_agreement') !== true) {
                                return $this->economicImpactPreview(error: 'Seleziona entrambe le dichiarazioni obbligatorie per calcolare l’anteprima.');
                            }
                            try {
                                $plan = app(CorrectContractCondition::class)->preview($contract, $record, [
                                    'amount' => Decimal::normalizeInput($get('amount')),
                                    'cycle' => $get('cycle'),
                                    'attribution_mode' => $get('attribution_mode'),
                                    'reason' => $get('reason'),
                                    'declared_input_error' => $get('declared_input_error'),
                                    'declared_no_new_agreement' => $get('declared_no_new_agreement'),
                                ]);
                            } catch (ValidationException $exception) {
                                return $this->economicImpactPreview(
                                    error: (string) (collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.'),
                                );
                            }

                            return $this->economicImpactPreview($plan);
                        }),
                ]),
            Section::make('Conferma dell’Impatto')
                ->description('Conferma soltanto dopo avere verificato tutti gli Esercizi mostrati nell’anteprima.')
                ->schema([
                    Checkbox::make('impact_confirmed')
                        ->label('Confermo l’impatto completo mostrato')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Conferma esplicitamente l’impatto completo mostrato per proseguire.'])
                        ->markAsRequired(),
                ]),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    private function economicImpactPreview(?ContractEconomicChangePlan $plan = null, ?string $error = null): View
    {
        return view('filament.resources.contracts.components.economic-impact-preview', [
            'error' => $error,
            'summary' => $plan === null ? null : [
                'operation_kind' => $plan->operationKind,
                'requested_date' => $this->formatDate($plan->requestedDate),
                'minimum_date' => $this->formatDate($plan->minimumDate),
                'effective_date' => $this->formatDate($plan->effectiveDate),
                'delay_reason' => $plan->delayReason,
                'no_prorata' => $plan->noProrata,
                'terms' => [
                    $this->termChange('Importo per ciclo', $this->formatMoney((string) $plan->oldTerms['amount']), $this->formatMoney((string) $plan->newTerms['amount'])),
                    $this->termChange('Ciclo', ContractCycleType::from((string) $plan->oldTerms['cycle'])->label(), ContractCycleType::from((string) $plan->newTerms['cycle'])->label()),
                    $this->termChange('Attribuzione', ContractAttributionMode::from((string) $plan->oldTerms['attribution_mode'])->label(), ContractAttributionMode::from((string) $plan->newTerms['attribution_mode'])->label()),
                ],
                'exercise_impacts' => collect($plan->exerciseImpacts)->map(fn (array $impact): array => [
                    'year' => (int) $impact['year'],
                    'allocation_before' => $this->formatMoney((string) $impact['allocation_before']),
                    'allocation_after' => $this->formatMoney((string) $impact['allocation_after']),
                    'allocation_delta' => $this->formatSignedMoney((string) $impact['allocation_delta']),
                ])->values()->all(),
            ],
        ]);
    }

    /** @return array{label: string, before: string, after: string, changed: bool} */
    private function termChange(string $label, string $before, string $after): array
    {
        return compact('label', 'before', 'after') + ['changed' => $before !== $after];
    }

    private function formatDate(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private function formatMoney(string $amount): string
    {
        return Number::currency((float) $amount, 'EUR', locale: 'it');
    }

    private function formatSignedMoney(string $amount): string
    {
        $formatted = $this->formatMoney($amount);

        return Decimal::compare($amount, '0.00') > 0 ? '+'.$formatted : $formatted;
    }
}
