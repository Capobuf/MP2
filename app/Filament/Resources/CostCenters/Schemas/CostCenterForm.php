<?php

namespace App\Filament\Resources\CostCenters\Schemas;

use App\Domain\CostCenters\CostCenterHierarchy;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\TenantCompany;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CostCenterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Denominazione')
                ->required()
                ->maxLength(255),
            Select::make('parent_id')
                ->label('Centro di Costo Padre')
                ->options(function (): array {
                    $tenant = Filament::getTenant();
                    $company = $tenant instanceof TenantCompany ? $tenant->company : null;

                    return $company instanceof Company
                        ? CostCenterHierarchy::forCompany((int) $company->id)->options(activeOnly: false)
                        : [];
                })
                ->placeholder('Nessun padre · Centro radice')
                ->searchable()
                ->visible(fn (string $operation): bool => $operation === 'create'),
            Placeholder::make('hierarchical_path')
                ->label('Percorso Corrente')
                ->content(fn (?CostCenter $record): string => $record instanceof CostCenter
                    ? CostCenterHierarchy::forCompany((int) $record->company_id)->path((int) $record->id)
                    : 'Centro radice')
                ->visible(fn (string $operation): bool => $operation !== 'create'),
        ]);
    }
}
