<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Filament\Resources\Exercises\ExerciseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExercises extends ListRecords
{
    protected static string $resource = ExerciseResource::class;

    public function getSubheading(): ?string
    {
        return 'Gestione degli Esercizi aziendali e del loro stato.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuovo Esercizio')];
    }
}
