<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Exercise;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewExercise extends ViewRecord
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Exercise $exercise */
        $exercise = $this->record;

        return [
            Action::make('createExpense')
                ->label('Nuova spesa')
                ->url(ExpenseResource::getUrl('create', ['exercise' => $exercise->id])),
        ];
    }
}
