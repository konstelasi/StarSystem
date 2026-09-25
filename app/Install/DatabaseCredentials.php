<?php

namespace App\Install;

/**
 * The database details a site owner types into the installer.
 */
final class DatabaseCredentials
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            host: trim((string) ($input['host'] ?? '')),
            port: (int) ($input['port'] ?? 3306),
            database: trim((string) ($input['database'] ?? '')),
            username: trim((string) ($input['username'] ?? '')),
            password: (string) ($input['password'] ?? ''),
        );
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
    }

    /**
     * @return array<string, string|int>
     */
    public function toEnv(): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->host,
            'DB_PORT' => $this->port,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->username,
            'DB_PASSWORD' => $this->password,
        ];
    }

    public function sameAs(self $other): bool
    {
        return $this->host === $other->host
            && $this->port === $other->port
            && $this->database === $other->database
            && $this->username === $other->username
            && hash_equals($this->password, $other->password);
    }
}
