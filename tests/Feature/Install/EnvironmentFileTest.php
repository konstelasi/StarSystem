<?php

namespace Tests\Feature\Install;

use App\Install\EnvironmentFile;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnvironmentFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/starsystem-env-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_missing_file_starts_from_the_example()
    {
        file_put_contents("{$this->dir}/.env.example", "# Settings\nAPP_NAME=StarSystem\nAPP_KEY=\nDB_HOST=127.0.0.1\n");

        $env = new EnvironmentFile("{$this->dir}/.env", "{$this->dir}/.env.example");
        $env->set(['DB_HOST' => 'localhost', 'STARDUST_PID_DIR' => '/home/site/storage/app/stardust/pids']);

        $this->assertSame(
            "# Settings\nAPP_NAME=StarSystem\nAPP_KEY=\nDB_HOST=localhost\nSTARDUST_PID_DIR=/home/site/storage/app/stardust/pids\n",
            file_get_contents("{$this->dir}/.env"),
        );
    }

    public function test_existing_keys_are_replaced_in_place_and_everything_else_is_kept()
    {
        file_put_contents("{$this->dir}/.env", "APP_NAME=Mine\n\n# my note\nexport DB_PASSWORD=old\nCUSTOM=\"keep me\"\n");

        $env = new EnvironmentFile("{$this->dir}/.env");
        $env->set(['DB_PASSWORD' => 'new']);

        $this->assertSame("APP_NAME=Mine\n\n# my note\nDB_PASSWORD=new\nCUSTOM=\"keep me\"\n", file_get_contents("{$this->dir}/.env"));
        $this->assertSame('keep me', $env->get('CUSTOM'));
    }

    public static function awkwardValues(): array
    {
        return [
            'plain' => ['secret'],
            'empty' => [''],
            'spaces' => ['correct horse battery staple'],
            'hash' => ['pa#ss'],
            'dollar' => ['pa$word${HOME}'],
            'quotes' => ['it\'s "quoted"'],
            'backslashes' => ['C:\\path\\n\\'],
            'base64 key' => ['base64:'.base64_encode(random_bytes(32))],
            'unicode' => ['pässwörd✓'],
            'equals' => ['a=b=c'],
        ];
    }

    #[DataProvider('awkwardValues')]
    public function test_values_read_back_exactly_as_written(string $value)
    {
        $env = new EnvironmentFile("{$this->dir}/.env");
        $env->set(['DB_PASSWORD' => $value, 'AFTER' => 'still-parsed']);

        $parsed = Dotenv::parse((string) file_get_contents("{$this->dir}/.env"));

        $this->assertSame($value, $parsed['DB_PASSWORD']);
        $this->assertSame('still-parsed', $parsed['AFTER']);
        $this->assertSame($value, $env->get('DB_PASSWORD'));
    }

    public function test_multi_line_values_are_refused()
    {
        $this->expectException(InvalidArgumentException::class);

        (new EnvironmentFile("{$this->dir}/.env"))->set(['DB_PASSWORD' => "one\ntwo"]);
    }

    public function test_writability_covers_a_file_that_does_not_exist_yet()
    {
        $this->assertTrue((new EnvironmentFile("{$this->dir}/.env"))->writable());
        $this->assertFalse((new EnvironmentFile("{$this->dir}/missing-folder/.env"))->writable());
    }
}
