<?php

namespace Tests;

use App\Update\ReleaseFeed;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Where the update feed keeps its record of the newest release seen.
     */
    protected string $feedRecord = '';

    protected function setUp(): void
    {
        // Updater::step() sets a 120 second limit for the rest of the process. On Windows
        // that counts wall-clock time and would end a long run, so lift it before each test.
        @set_time_limit(0);

        parent::setUp();

        // Not storage/app, so no test leaves a record there or reads one from another test.
        $this->feedRecord = sys_get_temp_dir().'/starsystem-feed-seen-'.bin2hex(random_bytes(6)).'.json';
        $this->app->instance(ReleaseFeed::class, new ReleaseFeed($this->feedRecord));
    }

    protected function tearDown(): void
    {
        if ($this->feedRecord !== '') {
            foreach ([$this->feedRecord, $this->feedRecord.'.lock', $this->feedRecord.'.tmp'] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
