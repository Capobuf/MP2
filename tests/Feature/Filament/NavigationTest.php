<?php

use App\Filament\Pages\CompanyAccess;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Suppliers\SupplierResource;

it('groups the product navigation by user intent', function () {
    expect(Dashboard::getNavigationGroup())->toBe('Panoramica')
        ->and(ExerciseResource::getNavigationGroup())->toBe('Operatività')
        ->and(ExpenseResource::getNavigationGroup())->toBe('Operatività')
        ->and(ProjectResource::getNavigationGroup())->toBe('Operatività')
        ->and(ContractResource::getNavigationGroup())->toBe('Operatività')
        ->and(SupplierResource::getNavigationGroup())->toBe('Anagrafiche')
        ->and(CostCenterResource::getNavigationGroup())->toBe('Anagrafiche')
        ->and(CompanyAudit::getNavigationGroup())->toBe('Controllo')
        ->and(CompanyAccess::getNavigationGroup())->toBe('Amministrazione')
        ->and(CompanySettings::getNavigationGroup())->toBe('Amministrazione');
});

it('provides an Italian assistive label for breadcrumbs', function () {
    app()->setLocale('it');

    expect(__('filament::components/breadcrumbs.label'))->toBe('Percorso di navigazione');
});
