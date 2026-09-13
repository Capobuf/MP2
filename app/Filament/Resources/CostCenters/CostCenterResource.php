<?php

namespace App\Filament\Resources\CostCenters;

use App\Actions\MasterData\MoveCostCenter;
use App\Actions\MasterData\SetCostCenterArchived;
use App\Domain\CostCenters\CostCenterHierarchy;
use App\Filament\Resources\CostCenters\Pages\CreateCostCenter;
use App\Filament\Resources\CostCenters\Pages\EditCostCenter;
use App\Filament\Resources\CostCenters\Pages\ListCostCenters;
use App\Filament\Resources\CostCenters\Pages\ViewCostCenter;
use App\Filament\Resources\CostCenters\Schemas\CostCenterForm;
use App\Filament\Resources\CostCenters\Tables\CostCentersTable;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** @extends resource<CostCenter> */
class CostCenterResource extends Resource
{
    protected static ?string $model = CostCenter::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Centri di Costo';

    protected static string|\UnitEnum|null $navigationGroup = 'Pianificazione';

    protected static ?string $modelLabel = 'centro di costo';

    protected static ?string $pluralModelLabel = 'centri di costo';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 50;

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
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $user instanceof User
            && $company instanceof Company
            && $user->can('viewAny', CostCenter::class);
    }

    /** @return Builder<CostCenter> */
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
            && $user->can('create', [CostCenter::class, $company]);
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
            ->modalHeading('Archivia Centro di Costo')
            ->modalDescription('Il centro di costo resterà consultabile nello storico, ma non sarà disponibile per nuove selezioni.')
            ->modalSubmitActionLabel('Archivia')
            ->successNotificationTitle('Centro di Costo Archiviato')
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
            ->modalHeading('Ripristina Centro di Costo')
            ->modalDescription('Il centro di costo tornerà disponibile per nuove selezioni.')
            ->modalSubmitActionLabel('Ripristina')
            ->successNotificationTitle('Centro di Costo Ripristinato')
            ->visible(fn (CostCenter $record): bool => $record->isArchived() && static::canEdit($record))
            ->action(function (CostCenter $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                app(SetCostCenterArchived::class)->execute($actor, $record, false, (string) Str::uuid());
                $record->refresh();
            });
    }

    public static function moveAction(): Action
    {
        return Action::make('move')
            ->label('Sposta')
            ->icon('heroicon-m-arrows-right-left')
            ->color('gray')
            ->modalHeading('Sposta Centro di Costo')
            ->modalDescription('La classificazione diretta e gli importi restano invariati. Cambiano i totali aggregati dei rami negli Esercizi Aperti indicati nell’anteprima.')
            ->modalSubmitActionLabel('Conferma Spostamento')
            ->visible(fn (CostCenter $record): bool => static::canEdit($record))
            ->form([
                Select::make('parent_id')
                    ->label('Nuovo Centro Padre')
                    ->options(fn (CostCenter $record): array => CostCenterHierarchy::forCompany((int) $record->company_id)
                        ->options(activeOnly: false, excludeSubtreeRoot: (int) $record->id))
                    ->default(fn (CostCenter $record): ?int => $record->parent_id)
                    ->placeholder('Nessun padre · Centro radice')
                    ->searchable()
                    ->live(),
                Placeholder::make('impact_preview')
                    ->hiddenLabel()
                    ->content(function (Get $get, CostCenter $record): View {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            $parentId = filled($get('parent_id')) ? (int) $get('parent_id') : null;
                            $plan = app(MoveCostCenter::class)->preview($actor, $record, $parentId);

                            return view('filament.resources.cost-centers.components.move-impact-preview', [
                                'error' => null,
                                'plan' => $plan,
                            ]);
                        } catch (ValidationException $exception) {
                            return view('filament.resources.cost-centers.components.move-impact-preview', [
                                'error' => collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.',
                                'plan' => null,
                            ]);
                        }
                    }),
                Textarea::make('reason')->label('Motivo')->rows(3),
                Checkbox::make('impact_confirmed')->label('Confermo l’anteprima corrente')->accepted()->required(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->action(function (CostCenter $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $parentId = filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null;
                $action = app(MoveCostCenter::class);
                $preview = $action->preview($actor, $record, $parentId);
                $action->confirm(
                    $actor,
                    $record,
                    $preview,
                    (string) $data['operation_id'],
                    isset($data['reason']) ? (string) $data['reason'] : null,
                );
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
