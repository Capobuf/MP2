<?php

namespace App\Filament\Resources\Projects;

use App\Domain\Company\Capability;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\ProjectExpensesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\ProjectTransitionsRelationManager;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<Project> */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Progetti';

    protected static string|\UnitEnum|null $navigationGroup = 'Operatività';

    protected static ?string $modelLabel = 'progetto';

    protected static ?string $pluralModelLabel = 'progetti';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
    }

    /** @return Builder<Project> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        return $company instanceof Company
            ? $query->whereBelongsTo($company, 'company')->with([
                'company.exercises',
                'transitions',
                'classifications.costCenter',
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
        return $record instanceof Project && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Project && auth()->user() instanceof User
            && auth()->user()->can('update', $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [ProjectExpensesRelationManager::class, ProjectTransitionsRelationManager::class];
    }
}
