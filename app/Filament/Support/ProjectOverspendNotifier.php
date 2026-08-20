<?php

namespace App\Filament\Support;

use App\Models\AuditEvent;
use Filament\Notifications\Notification;

final class ProjectOverspendNotifier
{
    public static function sendForOperation(string $operationId): void
    {
        $event = AuditEvent::query()->where('operation_id', $operationId)->first();
        $occurrences = $event?->overspendOccurrences() ?? [];
        if ($occurrences === []) {
            return;
        }

        $increased = collect($occurrences)->contains(fn (array $occurrence): bool => $occurrence['result'] === 'increased');
        $title = $increased ? 'Sovraspesa aumentata' : 'Sovraspesa creata';
        $body = collect($occurrences)->map(function (array $occurrence): string {
            $project = $occurrence['project_id'] === null ? 'Progetto' : 'Progetto #'.$occurrence['project_id'];

            return $project.': € '.$occurrence['variance_before'].' → € '.$occurrence['variance_after'];
        })->implode(' · ');

        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->send();
    }
}
