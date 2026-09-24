<?php

namespace Tests\Feature\Files;

use App\Files\UploadLimits;
use Tests\TestCase;

class UploadLimitsTest extends TestCase
{
    /**
     * @param  array<string, string>  $ini
     */
    private function limits(array $ini): UploadLimits
    {
        return new class($ini) extends UploadLimits
        {
            /**
             * @param  array<string, string>  $ini
             */
            public function __construct(private readonly array $ini) {}

            protected function ini(string $key): string|false
            {
                return $this->ini[$key] ?? false;
            }
        };
    }

    public function test_php_ini_sizes_are_parsed()
    {
        $this->assertSame(8 * 1024 * 1024, UploadLimits::parseIniSize('8M'));
        $this->assertSame(512 * 1024, UploadLimits::parseIniSize('512k'));
        $this->assertSame(2 * 1024 ** 3, UploadLimits::parseIniSize('2G'));
        $this->assertSame(1000, UploadLimits::parseIniSize('1000'));
        $this->assertSame(0, UploadLimits::parseIniSize('0'));
        $this->assertSame(0, UploadLimits::parseIniSize(false));
    }

    public function test_the_smallest_limit_wins_and_is_named()
    {
        config(['files.max_size' => 64 * 1024 * 1024]);

        $limits = $this->limits(['upload_max_filesize' => '20M', 'post_max_size' => '8M']);

        $this->assertSame(8 * 1024 * 1024, $limits->maxFileBytes());
        $this->assertSame('post_max_size', $limits->bindingSetting());
        $this->assertSame('8 MB', $limits->toArray()['maxFileSize']);
    }

    public function test_zero_means_no_limit()
    {
        config(['files.max_size' => 5 * 1024 * 1024]);

        $limits = $this->limits(['upload_max_filesize' => '0', 'post_max_size' => '0']);

        $this->assertSame(5 * 1024 * 1024, $limits->maxFileBytes());
        $this->assertSame('files.max_size', $limits->bindingSetting());
    }

    public function test_sizes_read_in_plain_units()
    {
        $this->assertSame('512 bytes', UploadLimits::human(512));
        $this->assertSame('1.5 KB', UploadLimits::human(1536));
        $this->assertSame('8 MB', UploadLimits::human(8 * 1024 * 1024));
        $this->assertSame('no limit', UploadLimits::human(PHP_INT_MAX));
    }
}
