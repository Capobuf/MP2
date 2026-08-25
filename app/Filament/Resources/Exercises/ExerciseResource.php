<?php

namespace App\Filament\Resources\Exercises;

use App\Domain\Company\Capability;
use App\Filament\Resources\Exercises\Pages\CloseExercise;
use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Filament\Resources\Exercises\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Exercises\Schemas\ExerciseForm;
use App\Filament\Resources\Exercises\Schemas\ExerciseInfolist;
use App\Filament\Resources\Exercises\Tables\ExercisesTable;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<Exercise> */
class ExerciseResource extends Resource
{
    protected static ?string $model = Exercise::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Esercizi';

    protected static string|\UnitEnum|null $navigationGroup = 'Operatività';

    protected static ?string $modelLabel = 'esercizio';

    protected static ?string $pluralModelLabel = 'esercizi';

    protected static ?string $recordTitleAttribute = 'year';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ExerciseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExerciseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExercisesTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
    }

    /** @return Builder<Exercise> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        return $company instanceof Company
            ? $query->whereBelongsTo($company, 'company')->with([
                'expenses.lines',
                'closingSnapshot',
                'lateCorrections.company',
                'lateCorrections.expense',
                'lateCorrections.expenseLine',
                'lateCorrections.originalExpenseLine',
                'lateCorrections.recordedBy',
                'lateCorrections.attachments',
                'historicalErrorAnnotations.company',
                'historicalErrorAnnotations.closingSnapshot',
                'historicalErrorAnnotations.recordedBy',
                'historicalErrorAnnotations.attachments',
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
        return $record instanceof Exercise && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExercises::route('/'),
            'create' => CreateExercise::route('/create'),
            'close' => CloseExercise::route('/{record}/close'),
            'view' => ViewExercise::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [ExpensesRelationManager::class];
    }
}
