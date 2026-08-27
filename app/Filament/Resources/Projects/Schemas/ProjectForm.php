<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Progetto')->schema([
                TextInput::make('title')->label('Titolo')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('Descrizione')->rows(3),
                Textarea::make('notes')->label('Note')->rows(3),
            ])->columns(2)->columnSpanFull(),
            Section::make('Configurazione iniziale')
                ->description('La classificazione iniziale appartiene all’Esercizio globale selezionato.')
                ->schema([
                    Select::make('initial_state')
                        ->label('Stato iniziale')
                        ->options(ProjectState::options())
                        ->native(false)
                        ->required(),
                    DatePicker::make('initial_effective_date')
                        ->label('Data di efficacia iniziale')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->placeholder('gg/mm/aaaa')
                        ->required(),
                    Select::make('cost_center_id')
                        ->label('Centro di Costo')
                        ->options(fn (): array => self::company() instanceof Company
                            ? CostCenter::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
                            : [])
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('name')->label('Nome')->required()->maxLength(255),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            $actor = auth()->user();
                            $company = self::company();
                            abort_unless($actor instanceof User && $company instanceof Company, 403);

                            return app(CreateCostCenter::class)->execute($actor, $company, $data, (string) Str::uuid())->id;
                        })
                        ->createOptionAction(fn (Action $action): Action => $action
                            ->label('Crea centro di costo')
                            ->modalHeading('Nuovo centro di costo')
                            ->visible(fn (): bool => self::canManageMasterData()))
                        ->placeholder('Non classificato'),
                ])->columns(3)->columnSpanFull(),
        ]);
    }

    private static function canManageMasterData(): bool
    {
        $actor = auth()->user();
        $company = self::company();

        return $actor instanceof User
            && $company instanceof Company
            && $actor->hasCapability($company, Capability::ManageMasterData);
    }

    private static function company(): ?Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company ? $company : null;
    }
}
