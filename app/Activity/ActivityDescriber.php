<?php

namespace App\Activity;

use App\Access\AbilityRegistry;
use App\Models\Activity;

/**
 * Puts the details stored with a log row into short phrases for the
 * Activity page, such as "Editor to Viewer" or "added Edit entries".
 *
 * Rows keep their details as plain data, so the wording can change later
 * without a migration.
 */
final class ActivityDescriber
{
    /**
     * @return list<string>
     */
    public static function details(Activity $row): array
    {
        $with = $row->properties ?? [];

        return match ($row->action) {
            ActivityAction::USER_ROLE_CHANGED => self::roleChange($with),
            ActivityAction::USER_REMOVED => self::role($with, 'was'),
            ActivityAction::USER_JOINED,
            ActivityAction::INVITE_SENT,
            ActivityAction::INVITE_RESENT,
            ActivityAction::INVITE_WITHDRAWN => self::role($with, 'as'),
            ActivityAction::ROLE_CREATED => self::created($with),
            ActivityAction::ROLE_UPDATED => self::updated($with),
            ActivityAction::ROLE_DUPLICATED => self::copied($with),
            ActivityAction::TWO_FACTOR_REQUIREMENT_CHANGED => self::twoFactor($with),
            ActivityAction::SITE_UPDATED => self::site($with),
            ActivityAction::SESSIONS_ENDED,
            ActivityAction::USER_SIGNED_OUT => self::devices($with),
            ActivityAction::USER_RESET_LINK => self::resetLink($with),
            ActivityAction::ACCOUNT_UPDATED,
            ActivityAction::ACCOUNT_EMAIL_REQUESTED => self::account($with),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function site(array $with): array
    {
        $theme = self::string($with, 'theme');
        $addresses = self::strings($with, 'addresses');

        return [
            ...($theme === null ? [] : ["theme set to {$theme}"]),
            ...(array_key_exists('addresses', $with) ? [$addresses === [] ? 'no models published' : 'addresses set to '.implode(', ', $addresses)] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function roleChange(array $with): array
    {
        $from = self::string($with, 'from');
        $to = self::string($with, 'to');

        return $from !== null && $to !== null ? ["{$from} to {$to}"] : [];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function role(array $with, string $word): array
    {
        $role = self::string($with, 'role');

        return $role === null ? [] : ["{$word} {$role}"];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function created(array $with): array
    {
        $count = count(self::strings($with, 'abilities'));
        $models = count(self::maps($with, 'models'));

        $lines = [match ($count) {
            0 => 'with no abilities',
            1 => 'with 1 ability',
            default => "with {$count} abilities",
        }];

        if ($models > 0) {
            $lines[] = $models === 1 ? 'and grants for 1 model' : "and grants for {$models} models";
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function updated(array $with): array
    {
        $lines = [];

        if (($old = self::string($with, 'renamed_from')) !== null) {
            $lines[] = "renamed from {$old}";
        }

        foreach (['added', 'removed'] as $key) {
            $abilities = self::strings($with, $key);

            if ($abilities !== []) {
                $lines[] = $key.' '.self::sentence(array_map(self::abilityLabel(...), $abilities));
            }
        }

        foreach (self::maps($with, 'models') as $label => $change) {
            foreach (['added', 'removed'] as $key) {
                $abilities = self::strings($change, $key);

                if ($abilities !== []) {
                    $lines[] = $key.' '.self::sentence(array_map(self::abilityLabel(...), $abilities))." on {$label}";
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function copied(array $with): array
    {
        $from = self::string($with, 'from');

        return $from === null ? [] : ["from {$from}"];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function devices(array $with): array
    {
        $count = $with['count'] ?? null;

        return is_int($count) ? [$count === 1 ? '1 device' : "{$count} devices"] : [];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function resetLink(array $with): array
    {
        return [($with['emailed'] ?? false) === true ? 'emailed to them' : 'link shown to the administrator'];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function twoFactor(array $with): array
    {
        $roles = self::strings($with, 'roles');

        return [$roles === [] ? 'no role has to use it now' : 'now required of '.self::sentence($roles)];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function account(array $with): array
    {
        $lines = [];
        $changed = self::strings($with, 'changed');

        if ($changed !== []) {
            $lines[] = self::sentence($changed);
        }

        $from = self::string($with, 'email_from');
        $to = self::string($with, 'email_to');

        if ($from !== null && $to !== null) {
            $lines[] = "{$from} to {$to}";
        } elseif ($to !== null) {
            $lines[] = "to {$to}";
        }

        return $lines;
    }

    private static function abilityLabel(string $ability): string
    {
        return app(AbilityRegistry::class)->label($ability);
    }

    /**
     * "A", "A and B", or "A, B and C".
     *
     * @param  list<string>  $items
     */
    private static function sentence(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private static function string(array $with, string $key): ?string
    {
        return isset($with[$key]) && is_string($with[$key]) && $with[$key] !== '' ? $with[$key] : null;
    }

    /**
     * The nested arrays under a key, such as the changes by model name.
     *
     * @param  array<string, mixed>  $with
     * @return array<string, array<string, mixed>>
     */
    private static function maps(array $with, string $key): array
    {
        $value = $with[$key] ?? [];
        $maps = [];

        foreach (is_array($value) ? $value : [] as $name => $inner) {
            if (is_array($inner)) {
                $maps[(string) $name] = $inner;
            }
        }

        return $maps;
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    private static function strings(array $with, string $key): array
    {
        $value = $with[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
