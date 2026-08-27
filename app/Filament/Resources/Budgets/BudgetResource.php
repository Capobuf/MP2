<?php

namespace App\Filament\Resources\Budgets;

use App\Domain\Company\Capability;
use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Filament\Resources\Budgets\Schemas\BudgetInfolist;
use App\Filament\Resources\Budgets\Tables\BudgetsTable;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<BudgetSnapshot> */
class BudgetResource extends Resource
{
    protected static ?string $model = BudgetSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Budget';

    protected static string|\UnitEnum|null $navigationGroup = 'Pianificazione';

    protected static ?string $modelLabel = 'budget';

    protected static ?string $pluralModelLabel = 'budget';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return BudgetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BudgetsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User && $company instanceof Company && $user->hasCapability($company, Capability::View);
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

    public static function canView(Model $record): bool
    {
        return $record instanceof BudgetSnapshot && auth()->user() instanceof User && auth()->user()->can('view', $record);
    }

    /** @return Builder<BudgetSnapshot> */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company ? parent::getEloquentQuery()->whereBelongsTo($company)->with(['exercise', 'approver', 'proposal', 'rows', 'evidence']) : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return ['index' => ListBudgets::route('/'), 'view' => ViewBudget::route('/{record}')];
    }
}
