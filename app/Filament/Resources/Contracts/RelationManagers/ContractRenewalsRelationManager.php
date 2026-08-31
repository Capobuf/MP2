<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\UpdateContractRenewal;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ContractRenewalsRelationManager extends RelationManager
{
    protected static string $relationship = 'renewalConfigurations';

    protected static ?string $title = 'Rinnovi e scadenze';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('effective_from')->label('Efficace dal')->date('d/m/Y'),
            IconColumn::make('automatic_renewal')->label('Rinnovo automatico')->boolean(),
            TextColumn::make('expiry_anchor_date')->label('Scadenza approvata')->date('d/m/Y')->placeholder('Scadenza non definita'),
            TextColumn::make('renewal_duration_months')->label('Durata mesi')->placeholder('—'),
            TextColumn::make('notice_days')->label('Preavviso giorni')->placeholder('—'),
            TextColumn::make('creator.name')->label('Autore')->placeholder('Autore originale non disponibile'),
        ])->headerActions([
            Action::make('updateRenewal')->label('Modifica rinnovo')->visible(fn (): bool => $this->canMutate())->form([
                DatePicker::make('effective_from')->label('Efficace dal')->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                Toggle::make('automatic_renewal')->label('Rinnovo automatico')->required()->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (! $state) {
                            $set('renewal_duration_months', null);
                        }
                        $set('impact_confirmed', false);
                    }),
                DatePicker::make('expiry_anchor_date')->label('Prossima scadenza')->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (blank($state)) {
                            $set('renewal_duration_months', null);
                        }
                        $set('impact_confirmed', false);
                    }),
                TextInput::make('renewal_duration_months')->label('Durata rinnovo in mesi')->integer()->minValue(1)
                    ->visible(fn (Get $get): bool => (bool) $get('automatic_renewal') && filled($get('expiry_anchor_date')))
                    ->required(fn (Get $get): bool => (bool) $get('automatic_renewal') && filled($get('expiry_anchor_date')))
                    ->live(onBlur: true)->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                TextInput::make('notice_days')->label('Preavviso in giorni')->integer()->minValue(0)
                    ->live(onBlur: true)->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false)),
                Textarea::make('reason')->label('Nota della modifica')
                    ->helperText('Richiesta perché esiste un Budget approvato in un Esercizio Aperto.')
                    ->visible(fn (): bool => $this->renewalReasonRequired())
                    ->required(fn (): bool => $this->renewalReasonRequired()),
                Placeholder::make('impact_preview')->label('Anteprima impatto')
                    ->content(fn (Get $get): string => $this->renewalPreview($get)),
                Checkbox::make('impact_confirmed')->label('Confermo l’anteprima corrente')->accepted()->required(),
                Hidden::make('expected_revision')->default(fn (): int => $this->ownerContract()->revision),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                $actor = auth()->user();
                $contract = $this->ownerContract();
                abort_unless($actor instanceof User, 403);
                app(UpdateContractRenewal::class)->execute($actor, $contract, $data, (string) $data['operation_id']);
            }),
        ])->defaultSort('effective_from', 'desc')
            ->recordActions([]);
    }

    private function canMutate(): bool
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();

        return $actor instanceof User && $contract instanceof Contract && ! $contract->isArchived()
            && $actor->can('update', $contract);
    }

    private function renewalReasonRequired(): bool
    {
        return Exercise::query()
            ->whereBelongsTo($this->ownerContract()->company, 'company')
            ->open()
            ->whereHas('budgets')
            ->exists();
    }

    private function renewalPreview(Get $get): string
    {
        $contract = $this->ownerContract();
        $exerciseYears = $contract->company->exercises()->open()->orderBy('year')->pluck('year')->all();
        $currentExpiry = $contract->nextExpiryDate()?->format('d/m/Y') ?? 'non definita';
        $newExpiry = filled($get('expiry_anchor_date')) ? (string) $get('expiry_anchor_date') : 'non definita';
        $currentRenewal = $contract->automatic_renewal ? 'attivo' : 'disattivo';
        $newRenewal = (bool) $get('automatic_renewal') ? 'attivo' : 'disattivo';
        $exercises = $exerciseYears === [] ? 'nessuno' : implode(', ', $exerciseYears);

        return "Rinnovo: {$currentRenewal} → {$newRenewal}. Scadenza: {$currentExpiry} → {$newExpiry}. "
            ."Esercizi Aperti interessati: {$exercises}. Budget e scadenze già materializzati non vengono riscritti; le Proposte coinvolte saranno marcate Da riallineare.";
    }

    private function ownerContract(): Contract
    {
        $contract = $this->getOwnerRecord();
        abort_unless($contract instanceof Contract, 404);

        return $contract;
    }
}
