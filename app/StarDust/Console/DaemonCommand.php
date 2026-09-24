<?php

namespace App\StarDust\Console;

use App\StarDust\StarDustFactory;
use Illuminate\Console\Command;
use StarDust\Daemon\PidFileGuard;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Exception\WatcherSingletonViolationException;
use StarDust\StarDust;

/**
 * Runs one of StarDust's persistent daemons under supervisor or systemd,
 * wired the same way StarDust's own bin/stardust wires them, but with
 * StarSystem's connection, logger and directories.
 */
class DaemonCommand extends Command
{
    public const DAEMONS = ['watcher', 'reconciler', 'liberator', 'chronicler'];

    protected $signature = 'stardust:daemon {name : watcher, reconciler, liberator or chronicler}';

    protected $description = 'Run a persistent StarDust daemon (server profile)';

    public function handle(StarDustFactory $factory): int
    {
        if (config('stardust.profile') !== 'server') {
            $this->components->error(
                'The daemons only run on the server profile (STARDUST_PROFILE=server). The shared profile uses the scheduled tick, and StarDust must never run both.'
            );

            return self::FAILURE;
        }

        $name = $this->argument('name');

        if (! in_array($name, self::DAEMONS, true)) {
            $this->components->error("Unknown daemon [{$name}].");

            return self::INVALID;
        }

        $stardust = $factory->make(reconnecting: $name === 'chronicler');
        $config = $stardust->config();
        $shutdown = $stardust->shutdownSignal($name);

        match ($name) {
            'watcher' => $this->watcher($stardust, $shutdown),
            'reconciler' => $stardust->pollLoop()->run($stardust->reconciler(), $shutdown, 0),
            'liberator' => $stardust->pollLoop()->run($stardust->liberator(), $shutdown, $config->liberatorIdleIntervalSeconds),
            // The same signal goes to both, so a shutdown lands mid-export
            // at the next chunk boundary.
            'chronicler' => $stardust->pollLoop()->run($stardust->chronicler($shutdown), $shutdown, $config->chroniclerIdleIntervalSeconds),
        };

        return self::SUCCESS;
    }

    /**
     * The watcher is a singleton, enforced by a pid file.
     */
    private function watcher(StarDust $stardust, ShutdownSignal $shutdown): void
    {
        try {
            $guard = PidFileGuard::acquire($stardust->config()->pidFileDir, 'watcher');
        } catch (WatcherSingletonViolationException $e) {
            $this->components->error($e->getMessage());

            return;
        }

        try {
            $stardust->pollLoop()->run($stardust->watcher(), $shutdown, $stardust->config()->watcherPollIntervalSeconds);
        } finally {
            $guard->release();
        }
    }
}
