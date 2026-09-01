<?php

namespace App\Filament\Platform\Resources\TenantCompanies;

use App\Filament\Platform\Resources\TenantCompanies\Pages\ListTenantCompanies;
use App\Filament\Platform\Resources\TenantCompanies\Tables\TenantCompaniesTable;
use App\Models\TenantCompany;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<TenantCompany> */
class TenantCompanyResource extends Resource
{
    protected static ?string $model = TenantCompany::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Aziende';

    protected static ?string $modelLabel = 'Azienda';

    protected static ?string $pluralModelLabel = 'Aziende';

    public static function table(Table $table): Table
    {
        return TenantCompaniesTable::configure($table);
    }

    /** @return Builder<TenantCompany> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('company');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListTenantCompanies::route('/')];
    }
}
