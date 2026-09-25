<?php

namespace App\Install;

use Closure;
use PDO;
use PDOException;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;
use Throwable;

/**
 * Tries the database details before anything is saved, on a throwaway
 * PDO, and runs StarDust's own server check on it.
 *
 * StarDust decides which servers it supports, so the installer asks it
 * rather than keeping a second list of version floors that could drift.
 * Every failure becomes a sentence a site owner can act on; the raw
 * driver message only helps their host's support desk, so it goes last.
 */
class DatabaseProbe
{
    /** @var Closure(DatabaseCredentials): PDO */
    private readonly Closure $connector;

    /**
     * @param  (Closure(DatabaseCredentials): PDO)|null  $connector
     */
    public function __construct(?Closure $connector = null)
    {
        $this->connector = $connector ?? fn (DatabaseCredentials $credentials) => new PDO(
            $credentials->dsn(),
            $credentials->username,
            $credentials->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }

    /**
     * @return array{ok: bool, message: string, engine: ?string, version: ?string}
     */
    public function check(DatabaseCredentials $credentials): array
    {
        try {
            $pdo = ($this->connector)($credentials);
        } catch (PDOException $e) {
            return $this->failed($this->explainConnection($e, $credentials));
        }

        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $version = is_string($version) ? $version : null;

        try {
            $engine = ServerEngineDetector::detect($pdo) === ServerEngine::MARIADB ? 'MariaDB' : 'MySQL';
        } catch (Throwable $e) {
            return $this->failed(
                'This database server is too old for StarSystem'.($version !== null ? " (it reports version {$version})" : '').'. '
                .'StarSystem needs MySQL 8.0.13 or newer, or MariaDB 10.11 or newer. '
                .'Ask your host to upgrade it, or choose a newer database server in your control panel if it offers one.',
                $version,
            );
        }

        // $pdo goes out of scope here, which closes the throwaway connection.
        return ['ok' => true, 'message' => "Connected to {$engine} {$version}.", 'engine' => $engine, 'version' => $version];
    }

    private function explainConnection(PDOException $e, DatabaseCredentials $credentials): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode();
        $detail = ' (The server said: '.$e->getMessage().')';

        return match ($code) {
            1045 => 'The database username or password was not accepted. Check them in your hosting control panel; on many hosts the username starts with your account name, like "account_user".'.$detail,
            // Shared hosts answer this, not 1049, for a misspelt database name.
            1044 => "Your database user isn't allowed to use a database called \"{$credentials->database}\". Check the name is spelled exactly as in your hosting control panel, and that the user has been added to that database with all privileges.".$detail,
            1049 => "There is no database called \"{$credentials->database}\" yet. Create it in your hosting control panel (often under \"MySQL Databases\"), give your database user access to it, then try again.".$detail,
            2002, 2003, 2005, 2006 => "Couldn't reach a database server at \"{$credentials->host}\" on port {$credentials->port}. On most hosts the server is \"localhost\" and the port is 3306.".$detail,
            default => "Couldn't connect to the database.{$detail}",
        };
    }

    /**
     * @return array{ok: false, message: string, engine: null, version: ?string}
     */
    private function failed(string $message, ?string $version = null): array
    {
        return ['ok' => false, 'message' => $message, 'engine' => null, 'version' => $version];
    }
}
