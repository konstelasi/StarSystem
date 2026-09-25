<?php

namespace Tests\Feature\Install;

use App\Install\DatabaseCredentials;
use App\Install\DatabaseProbe;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\ServerVersionPdo;
use Tests\TestCase;

class DatabaseProbeTest extends TestCase
{
    private function credentials(array $overrides = []): DatabaseCredentials
    {
        return DatabaseCredentials::fromArray([
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => config('database.connections.mysql.database'),
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            ...$overrides,
        ]);
    }

    private function probeReporting(string $version): DatabaseProbe
    {
        return new DatabaseProbe(fn () => new ServerVersionPdo($version));
    }

    public function test_the_test_database_passes()
    {
        $result = (new DatabaseProbe)->check($this->credentials());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertContains($result['engine'], ['MySQL', 'MariaDB']);
    }

    public function test_a_wrong_password_is_explained()
    {
        $result = (new DatabaseProbe)->check($this->credentials(['password' => 'not-the-password']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('username or password was not accepted', $result['message']);
    }

    public function test_a_database_the_user_cannot_use_is_explained()
    {
        $result = (new DatabaseProbe)->check($this->credentials(['database' => 'starsystem_no_such_database']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('isn\'t allowed to use a database called "starsystem_no_such_database"', $result['message']);
    }

    public function test_a_missing_database_is_explained()
    {
        // Only a user with global privileges gets 1049, so it's faked.
        $probe = new DatabaseProbe(function () {
            $e = new PDOException("SQLSTATE[HY000] [1049] Unknown database 'shop'");
            $e->errorInfo = ['HY000', 1049, "Unknown database 'shop'"];

            throw $e;
        });

        $result = $probe->check($this->credentials(['database' => 'shop']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('There is no database called "shop" yet', $result['message']);
    }

    public function test_an_unreachable_server_is_explained()
    {
        $result = (new DatabaseProbe)->check($this->credentials(['host' => '127.0.0.1', 'port' => 1]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("Couldn't reach a database server", $result['message']);
    }

    public static function unsupportedServers(): array
    {
        return [
            'MySQL 5.7' => ['5.7.44-log'],
            'MySQL 8.0.12' => ['8.0.12'],
            'MariaDB 10.6' => ['10.6.18-MariaDB'],
            'MariaDB 10.6 with the legacy prefix' => ['5.5.5-10.6.18-MariaDB-log'],
            'unparseable' => ['something else'],
        ];
    }

    #[DataProvider('unsupportedServers')]
    public function test_servers_below_the_stardust_floor_are_refused_in_plain_language(string $version)
    {
        $result = $this->probeReporting($version)->check($this->credentials());

        $this->assertFalse($result['ok']);
        $this->assertSame($version, $result['version']);
        $this->assertStringContainsString('too old for StarSystem', $result['message']);
        $this->assertStringContainsString('MySQL 8.0.13 or newer, or MariaDB 10.11 or newer', $result['message']);
    }

    public static function supportedServers(): array
    {
        return [
            'MySQL 8.0.13' => ['8.0.13', 'MySQL'],
            'MySQL 8.4' => ['8.4.2', 'MySQL'],
            'MariaDB 10.11' => ['10.11.9-MariaDB', 'MariaDB'],
            'MariaDB 10.11 with the legacy prefix' => ['5.5.5-10.11.9-MariaDB-log', 'MariaDB'],
            'MariaDB 11' => ['11.4.3-MariaDB', 'MariaDB'],
        ];
    }

    #[DataProvider('supportedServers')]
    public function test_servers_at_or_above_the_floor_pass(string $version, string $engine)
    {
        $result = $this->probeReporting($version)->check($this->credentials());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame($engine, $result['engine']);
    }
}
