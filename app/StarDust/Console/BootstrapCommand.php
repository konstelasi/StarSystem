<?php

namespace App\StarDust\Console;

use App\StarDust\StarDustService;
use Illuminate\Console\Command;

class BootstrapCommand extends Command
{
    protected $signature = 'stardust:bootstrap';

    protected $description = "Create StarDust's tables (safe to run more than once)";

    public function handle(StarDustService $stardust): int
    {
        $server = $stardust->server();

        if (! $server['supported']) {
            $this->components->error($server['error'] ?? 'Unsupported database server.');

            return self::FAILURE;
        }

        $stardust->bootstrap();

        $this->components->info("StarDust is ready on {$server['engine']} {$server['version']}.");

        return self::SUCCESS;
    }
}
