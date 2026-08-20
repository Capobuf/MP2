<?php

namespace App\Filament\Resources\Contracts;

use App\Domain\Company\Capability;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractAnnualSituationsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractConditionsRelationManager;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<Contract> */
class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Contratti';

    protected static ?string $modelLabel = 'contratto';

    protected static ?string $pluralModelLabel = 'contratti';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContractInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
    }

    /** @return Builder<Contract> */
    public static function getEloquentQuery(): Builder
    {
        $company = Filament::getTenant();
        $query = parent::getEloquentQuery();

        return $company instanceof Company
            ? $query->whereBelongsTo($company, 'company')->with([
                'company.exercises', 'supplier', 'conditions', 'lifecycleFacts',
                'classifications.costCenter', 'expenses.lines',
            ])
            : $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::ManageOperations);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Contract && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Contract && auth()->user() instanceof User
            && auth()->user()->can('update', $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'create' => CreateContract::route('/create'),
            'view' => ViewContract::route('/{record}'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [ContractAnnualSituationsRelationManager::class, ContractConditionsRelationManager::class];
    }
}
