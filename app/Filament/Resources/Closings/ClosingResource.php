<?php

namespace App\Filament\Resources\Closings;

use App\Domain\Company\Capability;
use App\Filament\Resources\Closings\Pages\ViewClosing;
use App\Filament\Resources\Closings\Schemas\ClosingInfolist;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<ClosingSnapshot> */
class ClosingResource extends Resource
{
    protected static ?string $model = ClosingSnapshot::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'chiusura';

    protected static ?string $pluralModelLabel = 'chiusure';

    public static function infolist(Schema $schema): Schema
    {
        return ClosingInfolist::configure($schema);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
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
        return $record instanceof ClosingSnapshot
            && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    /** @return Builder<ClosingSnapshot> */
    public static function getEloquentQuery(): Builder
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            ? parent::getEloquentQuery()->whereBelongsTo($company)->with(['exercise', 'closer', 'initialBudget', 'currentBudget', 'nextExercise', 'rows'])
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return ['view' => ViewClosing::route('/{record}')];
    }
}
