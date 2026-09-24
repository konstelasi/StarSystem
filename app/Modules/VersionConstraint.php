<?php

namespace App\Modules;

use InvalidArgumentException;

/**
 * Checks a version against a Composer-style constraint, for a module's
 * `requires.starsystem`.
 *
 * composer/semver isn't installed (Composer isn't a runtime dependency on
 * shared hosting), so this covers what module manifests need: `^1.2`,
 * `~1.2.3`, `1.2.*`, comparisons (`>=1.0 <2.0`, `>=1.0, <2.0`), hyphen
 * ranges (`1.0 - 2.0`), exact versions, `*`, and `||` between any of them.
 *
 * Upper bounds that come from ^, ~ and wildcards exclude pre-releases of
 * the next version, as Composer does, so `^1.0` doesn't accept 2.0.0-beta.
 */
final class VersionConstraint
{
    private const VERSION = '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';

    private const WILDCARD = '/^v?(\d+)(?:\.(\d+))?\.[*xX]$/';

    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);

        foreach (self::parse($constraint) as $comparators) {
            $all = true;

            foreach ($comparators as [$operator, $bound]) {
                if (! version_compare($version, $bound, $operator)) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    public static function isValid(string $constraint): bool
    {
        try {
            self::parse($constraint);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function isVersion(string $version): bool
    {
        return preg_match(self::VERSION, $version, $m) === 1 && isset($m[3]) && $m[3] !== '';
    }

    /**
     * @return list<list<array{string, string}>> Alternatives, each a list of
     *                                           comparators that must all hold.
     */
    private static function parse(string $constraint): array
    {
        $constraint = trim($constraint);

        if ($constraint === '') {
            throw new InvalidArgumentException('The version constraint is empty.');
        }

        $alternatives = [];

        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $group) {
            $alternatives[] = self::parseGroup($group, $constraint);
        }

        return $alternatives;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function parseGroup(string $group, string $constraint): array
    {
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $group, $m)) {
            [$low] = self::parts($m[1], $constraint);
            [$high, $given] = self::parts($m[2], $constraint);

            return [
                ['>=', self::join($low)],
                $given === 3 ? ['<=', self::join($high)] : ['<', self::bump($high, $given - 1)],
            ];
        }

        // `>= 1.0` means `>=1.0`; the remaining spaces or commas mean AND.
        $group = preg_replace('/(>=|<=|<>|!=|==|>|<|=|\^|~)\s+/', '$1', $group) ?? $group;
        $comparators = [];

        foreach (preg_split('/\s*,\s*|\s+/', trim($group)) ?: [] as $atom) {
            array_push($comparators, ...self::parseAtom($atom, $constraint));
        }

        return $comparators;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function parseAtom(string $atom, string $constraint): array
    {
        if ($atom === '*' || $atom === 'x' || $atom === 'X') {
            return [];
        }

        if (preg_match(self::WILDCARD, $atom, $m)) {
            $given = isset($m[2]) ? 2 : 1;
            $parts = [(int) $m[1], (int) ($m[2] ?? 0), 0, null];

            return [['>=', self::join($parts)], ['<', self::bump($parts, $given - 1)]];
        }

        if (str_starts_with($atom, '^')) {
            [$parts, $given] = self::parts(substr($atom, 1), $constraint);

            // Bump the first non-zero part given, or the last part given
            // when they're all zero: ^1.2 → <2.0.0, ^0.3 → <0.4.0, ^0.0 → <0.1.0.
            $position = $given - 1;
            for ($i = 0; $i < $given; $i++) {
                if ($parts[$i] !== 0) {
                    $position = $i;
                    break;
                }
            }

            return [['>=', self::join($parts)], ['<', self::bump($parts, $position)]];
        }

        if (str_starts_with($atom, '~')) {
            [$parts, $given] = self::parts(substr($atom, 1), $constraint);

            // ~1.2.3 → <1.3.0, ~1.2 → <2.0.0, ~1 → <2.0.0.
            return [['>=', self::join($parts)], ['<', self::bump($parts, max(0, $given - 2))]];
        }

        if (preg_match('/^(>=|<=|<>|!=|==|>|<|=)?(.+)$/', $atom, $m)) {
            [$parts] = self::parts($m[2], $constraint);
            $operator = match ($m[1]) {
                '', '=' => '==',
                '<>' => '!=',
                default => $m[1],
            };

            return [[$operator, self::join($parts)]];
        }

        throw new InvalidArgumentException("Can't read the version constraint [{$constraint}].");
    }

    /**
     * @return array{array{int, int, int, string|null}, int} The version's
     *                                                       parts, and how many numbers were given.
     */
    private static function parts(string $version, string $constraint): array
    {
        if (! preg_match(self::VERSION, $version, $m)) {
            throw new InvalidArgumentException("Can't read the version constraint [{$constraint}].");
        }

        $given = 1 + (int) (isset($m[2]) && $m[2] !== '') + (int) (isset($m[3]) && $m[3] !== '');

        return [[(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), $m[4] ?? null], $given];
    }

    /**
     * @param  array{int, int, int, string|null}  $parts
     */
    private static function join(array $parts): string
    {
        return "{$parts[0]}.{$parts[1]}.{$parts[2]}".($parts[3] !== null ? "-{$parts[3]}" : '');
    }

    /**
     * The exclusive upper bound after bumping one part. The `-dev` suffix
     * sorts below every pre-release in version_compare(), so pre-releases
     * of the bound are excluded too.
     *
     * @param  array{int, int, int, string|null}  $parts
     */
    private static function bump(array $parts, int $position): string
    {
        $numbers = [$parts[0], $parts[1], $parts[2]];
        $numbers[$position]++;

        for ($i = $position + 1; $i < 3; $i++) {
            $numbers[$i] = 0;
        }

        return implode('.', $numbers).'-dev';
    }

    private static function normalize(string $version): string
    {
        if (! self::isVersion($version)) {
            throw new InvalidArgumentException("[{$version}] isn't a version like 1.2.3.");
        }

        return self::join(self::parts($version, $version)[0]);
    }
}
