<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\UpdateContract;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    public string $operationId;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dati modificabili')
                ->description('Modifica i dati descrittivi. Scadenze, rinnovo e condizioni economiche si gestiscono dalle azioni dedicate del Contratto.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])
                        ->schema([
                            TextInput::make('title')
                                ->label('Titolo')
                                ->required()
                                ->maxLength(255),
                            Select::make('supplier_id')
                                ->label('Fornitore')
                                ->options(fn (): array => $this->supplierOptions())
                                ->required()
                                ->searchable()
                                ->helperText('Può cambiare solo prima del primo utilizzo economico del Contratto.'),
                        ])
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label('Note')
                        ->rows(6)
                        ->columnSpanFull(),
                ])
                ->extraAttributes(['class' => 'mp2-contract-edit'])
                ->columnSpanFull(),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Contract, 403);

        return app(UpdateContract::class)->execute($actor, $record, $data, $this->operationId);
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        return ContractResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Torna al contratto')->color('gray'),
        ];
    }

    /** @return array<int, string> */
    private function supplierOptions(): array
    {
        /** @var Contract $contract */
        $contract = $this->record;
        $options = Supplier::query()->where('company_id', $contract->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all();
        $current = $contract->supplier;
        if ($current->isArchived()) {
            $options[$current->id] = $current->legal_name.' · Archiviato';
        }

        return $options;
    }
}
