<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Domain\CostCenters\CostCenterHierarchy;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContractClassificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'classifications';

    protected static ?string $title = 'Classificazioni';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        $hierarchy = CostCenterHierarchy::forCompany((int) $this->contract()->company_id);

        return $table->columns([
            TextColumn::make('exercise.year')->label('Esercizio')->sortable(),
            TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (ContractExerciseClassification $record): string => $record->costCenter === null
                ? 'Non classificato'
                : $hierarchy->path((int) $record->cost_center_id)
                    .($record->costCenter->isArchived() ? ' · Archiviato' : '')),
        ])->defaultSort('exercise.year');
    }

    private function contract(): Contract
    {
        $record = $this->getOwnerRecord();
        abort_unless($record instanceof Contract, 404);

        return $record;
    }
}
