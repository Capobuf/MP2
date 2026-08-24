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
            if ($event->eventType() === AuditEventType::CompanyCreated) {
                $createdValue = $event->getAttribute('new_value');
                if (is_array($createdValue) && array_key_exists('overspend_note_required', $createdValue)) {
                    $required = (bool) $createdValue['overspend_note_required'];
                }

                continue;
            }

            $setting = $event->getAttribute('setting');
            if ($event->eventType() === AuditEventType::SettingChanged
                && $setting instanceof Setting
                && $setting === Setting::OverspendNoteRequired) {
                $required = (bool) $event->getAttribute('new_value');

                continue;
            }

            $affectedExerciseIds = $event->getAttribute('affected_exercise_ids');
            if (! $required || ! is_array($affectedExerciseIds) || ! in_array($exercise->id, array_map('intval', $affectedExerciseIds), true)) {
                continue;
            }

            $newValue = $event->getAttribute('new_value');
            if (! is_array($newValue)) {
                continue;
            }

            $projectActivity = $newValue['project_activity'] ?? null;
            $ownership = $newValue['ownership_impact'] ?? null;
            $note = is_array($projectActivity)
                ? ($projectActivity['overspend_note'] ?? null)
                : (is_array($ownership) ? ($ownership['overspend_note'] ?? null) : null);
            if (filled($note)) {
                continue;
            }

            foreach ($event->overspendOccurrences() as $occurrence) {
                if (! in_array($occurrence['result'], ['created', 'increased'], true)) {
                    continue;
                }
                $issue = self::issue($event, $occurrence['project_id']);
                $key = $issue['audit_event_id'].':'.($issue['project_id'] ?? '');
                $issues[$key] = $issue;
            }
        }

        return array_values($issues);
    }

    /** @return array<string, mixed> */
    private static function issue(AuditEvent $event, ?int $projectId): array
    {
        return [
            'code' => 'required_overspend_note_missing',
            'message' => 'Manca una Nota di sovraspesa che era obbligatoria quando l’operazione è stata registrata.',
            'source_type' => 'project',
            'source_id' => $projectId,
            'project_id' => $projectId,
            'audit_event_id' => $event->id,
        ];
    }
}
