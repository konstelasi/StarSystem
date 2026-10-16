<?php

namespace App\Security;

/**
 * Reads a browser and an operating system out of a user agent, well enough
 * for a person to recognise their own device in a list. It is a short list
 * of the common ones, not a parser, so anything else is "Unknown".
 */
final class DeviceName
{
    /**
     * @return array{browser: string, os: string}
     */
    public static function from(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        return ['browser' => self::browser($agent), 'os' => self::os($agent)];
    }

    /**
     * Order matters, because most browsers also say "Chrome" and "Safari".
     */
    private static function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') || str_contains($agent, 'EdgA/') || str_contains($agent, 'EdgiOS/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
            default => 'Unknown browser',
        };
    }

    /**
     * Android and iOS come before Linux and macOS, whose names they also
     * contain.
     */
    private static function os(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown system',
        };
    }
}
