<?php

namespace App\Filament\Resources\Suppliers;

use App\Actions\MasterData\SetSupplierArchived;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @extends resource<Supplier> */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Fornitori';

    protected static string|\UnitEnum|null $navigationGroup = 'Anagrafiche';

    protected static ?string $modelLabel = 'fornitore';

    protected static ?string $pluralModelLabel = 'fornitori';

    protected static ?string $recordTitleAttribute = 'legal_name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User
            && $company instanceof Company
            && $user->can('viewAny', Supplier::class);
    }

    /** @return Builder<Supplier> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        if (! $company instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereBelongsTo($company, 'company');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User
            && $company instanceof Company
            && $user->can('create', [Supplier::class, $company]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Supplier
            && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Supplier
            && auth()->user() instanceof User
            && auth()->user()->can('update', $record);
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archivia')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Archivia fornitore')
            ->modalDescription('Il fornitore resterà consultabile nello storico, ma non sarà disponibile per nuove selezioni.')
            ->modalSubmitActionLabel('Archivia')
            ->successNotificationTitle('Fornitore archiviato')
            ->visible(fn (Supplier $record): bool => ! $record->isArchived() && static::canEdit($record))
            ->action(function (Supplier $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                app(SetSupplierArchived::class)->execute($actor, $record, true, (string) Str::uuid());
                $record->refresh();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Ripristina')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Ripristina fornitore')
            ->modalDescription('Il fornitore tornerà disponibile per nuove selezioni.')
            ->modalSubmitActionLabel('Ripristina')
            ->successNotificationTitle('Fornitore ripristinato')
            ->visible(fn (Supplier $record): bool => $record->isArchived() && static::canEdit($record))
            ->action(function (Supplier $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                app(SetSupplierArchived::class)->execute($actor, $record, false, (string) Str::uuid());
                $record->refresh();
            });
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
