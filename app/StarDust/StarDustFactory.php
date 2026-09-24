<?php

namespace App\StarDust;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDO;
use StarDust\Chronicler\DsnPdoConnector;
use StarDust\Config\Config;
use StarDust\StarDust;

/**
 * Builds StarDust engines on their own PDO connection.
 *
 * StarDust must never share Laravel's PDO. It needs ERRMODE_EXCEPTION and
 * EMULATE_PREPARES=false, and it calls beginTransaction() itself, which
 * throws if DB::transaction() already has one open on the same connection.
 */
class StarDustFactory
{
    /** @var array<int, int|bool> */
    public const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * @param  bool  $reconnecting  Wire a reconnect factory so a dropped
     *                              connection mid-export recovers in-process.
     *                              StarDust's CLI does this for the chronicler
     *                              and for ticks that run exports.
     */
    public function make(bool $reconnecting = false): StarDust
    {
        [$dsn, $user, $pass] = $this->credentials();

        return new StarDust(new Config(
            pdo: new PDO($dsn, $user, $pass, self::PDO_OPTIONS),
            logger: Log::channel('stardust'),
            artifactDir: $this->directory(config('stardust.artifact_dir')),
            pidFileDir: $this->directory(config('stardust.pid_dir')),
            lockNamespace: config('stardust.lock_namespace') ?: null,
            tickBudgetSeconds: (int) config('stardust.tick.budget'),
            pdoConnector: $reconnecting ? new DsnPdoConnector($dsn, $user, $pass, self::PDO_OPTIONS) : null,
        ));
    }

    /**
     * @return array{string, string, string}
     */
    public function credentials(): array
    {
        $name = config('stardust.connection');
        $connection = config("database.connections.{$name}");

        if (! is_array($connection) || ! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException(
                "StarDust needs a MySQL or MariaDB connection; [{$name}] is not one."
            );
        }

        $dsn = empty($connection['unix_socket'])
            ? sprintf('mysql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database'])
            : sprintf('mysql:unix_socket=%s;dbname=%s', $connection['unix_socket'], $connection['database']);

        $dsn .= ';charset='.($connection['charset'] ?? 'utf8mb4');

        return [$dsn, (string) $connection['username'], (string) $connection['password']];
    }

    private function directory(string $path): string
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
