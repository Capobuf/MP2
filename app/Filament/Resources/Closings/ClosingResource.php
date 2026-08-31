<?php

namespace App\Filament\Resources\Closings;

use App\Filament\Resources\Closings\Pages\ViewClosing;
use App\Filament\Resources\Closings\Schemas\ClosingInfolist;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\TenantCompany;
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
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User && $company instanceof Company
            && $user->can('viewAny', ClosingSnapshot::class);
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
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company
            ? parent::getEloquentQuery()->whereBelongsTo($company)->with([
                'exercise',
                'closer',
                'initialBudget',
                'currentBudget',
                'nextExercise',
                'rows',
                'lateCorrections.company',
                'lateCorrections.expense',
                'lateCorrections.expenseLine',
                'lateCorrections.originalExpenseLine',
                'lateCorrections.recordedBy',
                'lateCorrections.attachments',
                'historicalErrorAnnotations.company',
                'historicalErrorAnnotations.recordedBy',
                'historicalErrorAnnotations.attachments',
            ])
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getIndexUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
    ): string {
        return ExerciseResource::getUrl(
            'index',
            $parameters,
            $isAbsolute,
            $panel,
            $tenant,
            $shouldGuessMissingParameters,
        );
    }

    public static function getPages(): array
    {
        return ['view' => ViewClosing::route('/{record}')];
    }
}
