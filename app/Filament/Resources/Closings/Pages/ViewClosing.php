<?php

namespace App\Filament\Resources\Closings\Pages;

use App\Filament\Resources\Closings\ClosingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClosing extends ViewRecord
{
    protected static string $resource = ClosingResource::class;

    public function getTitle(): string
    {
        return 'Chiusura '.$this->record->exercise_year;
    }
}
