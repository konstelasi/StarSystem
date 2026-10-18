<?php

namespace App\Activity;

use App\Models\Activity;

/**
 * A log row as the pages show it, so the Activity page and a person's page
 * describe an event in the same words.
 */
final class ActivityRow
{
    /**
     * @return array{id: int, at: string, actor: string|null, action: string, label: string, subject: string|null, details: list<string>, ip: string|null}
     */
    public static function from(Activity $row): array
    {
        return [
            'id' => $row->id,
            'at' => $row->created_at->toIso8601String(),
            'actor' => $row->actor_name,
            'action' => $row->action,
            'label' => ActivityAction::label($row->action),
            'subject' => $row->subject_label,
            'details' => ActivityDescriber::details($row),
            'ip' => $row->ip_address,
        ];
    }
}
