<?php

namespace App\Filament\Resources\Closings\Pages;

use App\Filament\Resources\Closings\ClosingResource;
use App\Models\ClosingSnapshot;
use Filament\Resources\Pages\ViewRecord;

class ViewClosing extends ViewRecord
{
    protected static string $resource = ClosingResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        if (! $record instanceof ClosingSnapshot) {
            throw new \UnexpectedValueException('Invalid Closing Snapshot record.');
        }

        return 'Chiusura '.$record->exercise_year;
    }
}
