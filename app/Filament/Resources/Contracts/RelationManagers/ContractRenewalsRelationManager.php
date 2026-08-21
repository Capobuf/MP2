<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\UpdateContractRenewal;
use App\Domain\Company\Capability;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
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
            TextColumn::make('creator.name')->label('Autore'),
        ])->headerActions([
            Action::make('updateRenewal')->label('Modifica rinnovo')->visible(fn (): bool => $this->canMutate())->form([
                DatePicker::make('effective_from')->label('Efficace dal')->required(),
                Toggle::make('automatic_renewal')->label('Rinnovo automatico')->required(),
                DatePicker::make('expiry_anchor_date')->label('Prossima scadenza'),
                TextInput::make('renewal_duration_months')->label('Durata rinnovo in mesi')->integer()->minValue(1),
                TextInput::make('notice_days')->label('Preavviso in giorni')->integer()->minValue(0),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])->action(function (array $data): void {
                $actor = auth()->user();
                $contract = $this->getOwnerRecord();
                abort_unless($actor instanceof User && $contract instanceof Contract, 403);
                $contract->refresh();
                $data['expected_revision'] = $contract->revision;
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
            && $actor->hasCapability($contract->company, Capability::ManageOperations);
    }
}
