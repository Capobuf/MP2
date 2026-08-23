<?php

namespace App\Domain\Closing;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Setting;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;

final class ClosingOverspendNotes
{
    /** @return list<array<string, mixed>> */
    public static function missingRequired(Company $company, Exercise $exercise): array
    {
        $required = false;
        $issues = [];
        $events = AuditEvent::query()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            if ($event->eventType() === AuditEventType::SettingChanged
                && $event->setting === Setting::OverspendNoteRequired) {
                $required = (bool) $event->new_value;
                continue;
            }
            if (! $required || ! in_array($exercise->id, array_map('intval', $event->affected_exercise_ids ?? []), true)) {
                continue;
            }
            $newValue = $event->new_value;
            if (! is_array($newValue)) {
                continue;
            }

            $projectActivity = $newValue['project_activity'] ?? null;
            if (is_array($projectActivity)
                && self::overspendOccurred($projectActivity['overspend'] ?? null)
                && ! filled($projectActivity['overspend_note'] ?? null)) {
                $issues[] = self::issue($event, $event->reference_id);
            }

            $ownership = $newValue['ownership_impact'] ?? null;
            if (! is_array($ownership)) {
                continue;
            }
            $note = $ownership['overspend_note'] ?? null;
            foreach ((array) ($ownership['project_impacts'] ?? []) as $projectId => $impact) {
                if (is_array($impact) && self::overspendOccurred($impact['overspend'] ?? null) && ! filled($note)) {
                    $issues[] = self::issue($event, is_numeric($projectId) ? (int) $projectId : null);
                }
            }
        }

        return collect($issues)
            ->unique(fn (array $issue): string => $issue['audit_event_id'].':'.($issue['project_id'] ?? ''))
            ->values()
            ->all();
    }

    private static function overspendOccurred(mixed $overspend): bool
    {
        if (! is_array($overspend)) {
            return false;
        }

        return in_array((string) ($overspend['result'] ?? ''), ['created', 'increased'], true);
    }

    /** @return array<string, mixed> */
    private static function issue(AuditEvent $event, mixed $projectId): array
    {
        return [
            'code' => 'required_overspend_note_missing',
            'message' => 'Manca una Nota di sovraspesa che era obbligatoria quando l’operazione è stata registrata.',
            'source_type' => 'project',
            'source_id' => is_numeric($projectId) ? (int) $projectId : null,
            'project_id' => is_numeric($projectId) ? (int) $projectId : null,
            'audit_event_id' => $event->id,
        ];
    }
}
