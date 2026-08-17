<?php

namespace App\Filament\Resources\CostCenters;

use App\Actions\MasterData\SetCostCenterArchived;
use App\Domain\Company\Capability;
use App\Filament\Resources\CostCenters\Pages\CreateCostCenter;
use App\Filament\Resources\CostCenters\Pages\EditCostCenter;
use App\Filament\Resources\CostCenters\Pages\ListCostCenters;
use App\Filament\Resources\CostCenters\Pages\ViewCostCenter;
use App\Filament\Resources\CostCenters\Schemas\CostCenterForm;
use App\Filament\Resources\CostCenters\Tables\CostCentersTable;
use App\Models\Company;
use App\Models\CostCenter;
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

/** @extends resource<CostCenter> */
class CostCenterResource extends Resource
{
    protected static ?string $model = CostCenter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Centri di Costo';

    protected static ?string $modelLabel = 'centro di costo';

    protected static ?string $pluralModelLabel = 'centri di costo';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function getTitleCaseModelLabel(): string
    {
        return 'Centro di Costo';
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return 'Centri di Costo';
    }

    public static function form(Schema $schema): Schema
    {
        return CostCenterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostCentersTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
    }

    /** @return Builder<CostCenter> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereBelongsTo($company, 'company');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && $user->hasCapability($company, Capability::ManageMasterData);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof CostCenter
            && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof CostCenter
            && auth()->user() instanceof User
            && auth()->user()->can('update', $record);
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archivia')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Archivia centro di costo')
            ->modalDescription('Il centro di costo resterà consultabile nello storico, ma non sarà disponibile per nuove selezioni.')
            ->modalSubmitActionLabel('Archivia')
            ->successNotificationTitle('Centro di costo archiviato')
            ->visible(fn (CostCenter $record): bool => ! $record->isArchived() && static::canEdit($record))
            ->action(function (CostCenter $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                app(SetCostCenterArchived::class)->execute($actor, $record, true, (string) Str::uuid());
                $record->refresh();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Ripristina')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Ripristina centro di costo')
            ->modalDescription('Il centro di costo tornerà disponibile per nuove selezioni.')
            ->modalSubmitActionLabel('Ripristina')
            ->successNotificationTitle('Centro di costo ripristinato')
            ->visible(fn (CostCenter $record): bool => $record->isArchived() && static::canEdit($record))
            ->action(function (CostCenter $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                app(SetCostCenterArchived::class)->execute($actor, $record, false, (string) Str::uuid());
                $record->refresh();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostCenters::route('/'),
            'create' => CreateCostCenter::route('/create'),
            'view' => ViewCostCenter::route('/{record}'),
            'edit' => EditCostCenter::route('/{record}/edit'),
        ];
    }
}
