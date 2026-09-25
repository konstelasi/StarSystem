<?php

namespace App\Update;

use App\Models\TickRun;
use App\StarDust\StarDustService;
use App\StarDust\TickPause;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Updates StarSystem in place, one short step per request, and puts the
 * previous version back if a step fails once the site has been touched.
 *
 * All progress lives in a state file, never the database, because the
 * database is being migrated. Every step is safe to run again, so a
 * request the host killed is simply repeated by the next one.
 *
 * The state file and the step names are a contract across versions:
 * after the swap the new version's code reads a file the old one wrote,
 * and after a rollback the old code reads what the new one wrote. Only add
 * to them, and bump FORMAT if that is ever impossible.
 */
class Updater
{
    public const FORMAT = 1;

    public const STEPS = ['download', 'extract', 'check', 'pause', 'swap', 'migrate', 'bootstrap', 'caches', 'finish'];

    public const ROLLBACK_STEPS = ['unmigrate', 'restore', 'caches', 'finish'];

    /**
     * Steps that only prepare. When one fails there is nothing to undo.
     */
    public const PREPARE_STEPS = ['download', 'extract', 'check'];

    /**
     * Room for the zip, the unpacked release and the backup of the old one.
     */
    public const MIN_FREE_BYTES = 250 * 1024 * 1024;

    public function __construct(
        private readonly string $root,
        private readonly string $work,
        private readonly TickPause $pause,
        private readonly float $sliceSeconds = 10.0,
    ) {}

    public function currentVersion(): string
    {
        return trim((string) @file_get_contents($this->root.'/VERSION')) ?: '0.0.0';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        $state = json_decode((string) @file_get_contents($this->work.'/state.json'), true);

        return is_array($state) && ($state['format'] ?? null) === self::FORMAT ? $state : null;
    }

    public function running(): bool
    {
        return in_array($this->state()['status'] ?? null, ['running', 'stalled'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function start(Release $release): array
    {
        return $this->locked(function () use ($release) {
            if ($this->running()) {
                throw new UpdateException('An update is already under way.');
            }

            $current = $this->currentVersion();

            if (! $release->newerThan($current)) {
                throw new UpdateException("StarSystem {$current} is already up to date.");
            }

            // Whatever an earlier update left, including its backup.
            foreach (array_diff(scandir($this->work) ?: [], ['.', '..', 'lock']) as $entry) {
                Files::remove($this->work.'/'.$entry);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $state = [
                'format' => self::FORMAT,
                'status' => 'running',
                'mode' => 'update',
                'step' => self::STEPS[0],
                'from' => $current,
                'to' => $release->toArray(),
                'extracted' => 0,
                'units' => [],
                'added' => [],
                'batch' => null,
                'secret' => null,
                'was_down' => null,
                'waiting' => null,
                'error' => null,
                'failed_step' => null,
                'rollback_error' => null,
                'started_at' => $now,
                'updated_at' => $now,
                'finished_at' => null,
            ];

            $this->save($state);

            return $state;
        }) ?? throw new UpdateException('An update step is running right now. Try again in a moment.');
    }

    /**
     * Run the next step, if nothing else is running one.
     *
     * @return array{state: array<string, mixed>|null, busy: bool}
     */
    public function step(): array
    {
        $state = $this->locked(function () {
            $state = $this->state();

            if ($state === null || $state['status'] !== 'running') {
                return $state;
            }

            @set_time_limit(120);

            $step = (string) $state['step'];

            try {
                if ($this->run($step, $state)) {
                    $this->advance($state);
                }
            } catch (Throwable $e) {
                $this->fail($state, $step, $e);
            }

            $this->save($state);

            return $state;
        }, orElse: false);

        return $state === false ? ['state' => $this->state(), 'busy' => true] : ['state' => $state, 'busy' => false];
    }

    /**
     * Try a stalled rollback again, after the owner fixed what stopped it.
     *
     * @return array<string, mixed>|null
     */
    public function retry(): ?array
    {
        return $this->locked(function () {
            $state = $this->state();

            if ($state !== null && $state['status'] === 'stalled') {
                $state['status'] = 'running';
                $this->save($state);
            }

            return $state;
        });
    }

    /**
     * The cookie that lets this browser past the maintenance page the
     * update puts up, so an owner who gets signed out can still sign in
     * and finish or roll back.
     */
    public function bypassCookie(): ?Cookie
    {
        $secret = $this->state()['secret'] ?? null;

        return is_string($secret) && app()->isDownForMaintenance() ? MaintenanceModeBypassCookie::create($secret) : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function run(string $step, array &$state): bool
    {
        return match ($state['mode'].':'.$step) {
            'update:download' => $this->download($state),
            'update:extract' => $this->extract($state),
            'update:check' => $this->check($state),
            'update:pause' => $this->pauseSite($state),
            'update:swap' => $this->swap($state),
            'update:migrate' => $this->migrate($state),
            'update:bootstrap' => $this->bootstrap(),
            'update:caches', 'rollback:caches' => $this->clearCaches(),
            'update:finish', 'rollback:finish' => $this->finish($state),
            'rollback:unmigrate' => $this->unmigrate($state),
            'rollback:restore' => $this->restore($state),
            default => throw new UpdateException("Unknown update step [{$step}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function advance(array &$state): void
    {
        $steps = $state['mode'] === 'rollback' ? self::ROLLBACK_STEPS : self::STEPS;
        $next = array_search($state['step'], $steps, true);

        $state['step'] = $steps[$next + 1] ?? null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function fail(array &$state, string $step, Throwable $e): void
    {
        Log::error("StarSystem update step [{$state['mode']}:{$step}] failed.", ['exception' => $e]);

        $message = $e instanceof UpdateException ? $e->getMessage() : mb_substr($e::class.': '.$e->getMessage(), 0, 1000);

        if ($state['mode'] === 'rollback') {
            $state['status'] = 'stalled';
            $state['rollback_error'] = $message;

            return;
        }

        $state['error'] = $message;
        $state['failed_step'] = $step;

        if (in_array($step, self::PREPARE_STEPS, true)) {
            $this->cleanUp();
            $state['status'] = 'failed';
            $state['step'] = null;
            $state['finished_at'] = CarbonImmutable::now()->toIso8601String();

            return;
        }

        $state['mode'] = 'rollback';
        $state['step'] = self::ROLLBACK_STEPS[0];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function download(array $state): bool
    {
        $release = Release::fromArray($state['to']);
        $zip = $this->zipPath();

        if (is_file($zip) && hash_equals($release->sha256, (string) hash_file('sha256', $zip))) {
            return true;
        }

        $free = @disk_free_space($this->work);

        if ($free !== false && $free < self::MIN_FREE_BYTES) {
            throw new UpdateException(sprintf(
                'There isn\'t enough free disk space to update: %d MB free, %d MB needed.',
                $free / 1048576, self::MIN_FREE_BYTES / 1048576,
            ));
        }

        $part = $zip.'.part';
        @unlink($part);

        try {
            Http::timeout(600)->connectTimeout(15)->sink($part)->get($release->zip)->throw();
        } catch (Throwable $e) {
            @unlink($part);

            throw new UpdateException('Couldn\'t download the new version: '.$e->getMessage());
        }

        if (! hash_equals($release->sha256, (string) hash_file('sha256', $part))) {
            @unlink($part);

            throw new UpdateException('The download doesn\'t match the published checksum, so it may be damaged or not the real release. Nothing was changed.');
        }

        rename($part, $zip);

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function extract(array &$state): bool
    {
        $package = new ReleasePackage($this->zipPath());
        $staging = $this->work.'/staging';

        if ($state['extracted'] === 0) {
            $package->validate();
            Files::remove($staging);
            mkdir($staging, 0775, true);
        }

        $next = $package->extract($staging, (int) $state['extracted'], $this->sliceSeconds);

        if ($next !== null) {
            $state['extracted'] = $next;

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function check(array &$state): bool
    {
        $staged = $this->stagedRoot();
        $version = trim((string) @file_get_contents($staged.'/VERSION'));

        if ($version !== $state['to']['version']) {
            throw new UpdateException("The download says it is version [{$version}], not {$state['to']['version']}. Nothing was changed.");
        }

        $problems = ReleasePackage::platformProblems($staged);

        if ($problems !== []) {
            throw new UpdateException(implode(' ', $problems).' Nothing was changed.');
        }

        $units = FileSwap::units($staged);
        $state['units'] = $units;
        $state['added'] = $this->fileSwap()->additions($units);

        return true;
    }

    /**
     * Pause the tick, wait for a tick that is already running to end, then
     * put up the maintenance page.
     *
     * @param  array<string, mixed>  $state
     */
    private function pauseSite(array &$state): bool
    {
        $this->pause->pause('update');

        if ($this->tickRunning()) {
            $state['waiting'] = 'tick';

            return false;
        }

        $state['waiting'] = null;
        $state['was_down'] ??= app()->isDownForMaintenance();

        if (! $state['was_down'] && ! app()->isDownForMaintenance()) {
            $state['secret'] ??= Str::random(40);
            Artisan::call('down', ['--secret' => $state['secret'], '--retry' => 60]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function swap(array $state): bool
    {
        // What this request still needs after the swap, loaded while the
        // old files are in place; anything loaded later comes from the new.
        foreach ([JsonResponse::class, MaintenanceModeBypassCookie::class, Cookie::class] as $class) {
            class_exists($class);
        }

        $this->fileSwap()->apply($state['units']);
        $this->resetOpcache();

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function migrate(array &$state): bool
    {
        // Kept from the first attempt, so a retried migrate still knows
        // which batches the update added.
        $state['batch'] ??= $this->lastBatch();

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new UpdateException('The database update failed: '.trim(Artisan::output()));
        }

        return true;
    }

    private function bootstrap(): bool
    {
        app(StarDustService::class)->bootstrap();

        return true;
    }

    private function clearCaches(): bool
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear', 'clear-compiled'] as $command) {
            Artisan::call($command);
        }

        $this->resetOpcache();

        return true;
    }

    /**
     * Undo the migrations the update ran, with the new version's code still
     * in place, since only it knows how.
     *
     * @param  array<string, mixed>  $state
     */
    private function unmigrate(array $state): bool
    {
        if ($state['batch'] === null) {
            return true;
        }

        while (($last = $this->lastBatch()) > $state['batch']) {
            Artisan::call('migrate:rollback', ['--force' => true]);

            if ($this->lastBatch() >= $last) {
                throw new UpdateException('The database changes couldn\'t be undone: '.trim(Artisan::output()));
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function restore(array $state): bool
    {
        $this->fileSwap()->restore($state['units'], $state['added']);
        $this->resetOpcache();

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finish(array &$state): bool
    {
        if (! $state['was_down'] && app()->isDownForMaintenance()) {
            Artisan::call('up');
        }

        $this->pause->resume();
        $this->cleanUp(keepBackup: $state['mode'] === 'update');

        $state['status'] = $state['mode'] === 'update' ? 'done' : 'rolled_back';
        $state['secret'] = null;
        $state['finished_at'] = CarbonImmutable::now()->toIso8601String();

        return true;
    }

    /**
     * The previous version's files stay in backup/ after a successful
     * update, for recovery by hand, until the next update starts.
     */
    private function cleanUp(bool $keepBackup = false): void
    {
        foreach (['release.zip', 'release.zip.part', 'staging', 'discard', ...($keepBackup ? [] : ['backup'])] as $entry) {
            Files::remove($this->work.'/'.$entry);
        }
    }

    private function tickRunning(): bool
    {
        $budget = max((int) config('stardust.tick.budget'), (int) config('stardust.tick.url_budget'));

        return TickRun::query()
            ->whereNull('finished_at')
            ->where('started_at', '>=', CarbonImmutable::now()->subSeconds($budget + 60))
            ->exists();
    }

    private function lastBatch(): int
    {
        $repository = app('migrator')->getRepository();

        return $repository->repositoryExists() ? $repository->getNextBatchNumber() - 1 : 0;
    }

    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function fileSwap(): FileSwap
    {
        return new FileSwap($this->root, $this->stagedRoot(), $this->work.'/backup', $this->work.'/discard');
    }

    private function stagedRoot(): string
    {
        return $this->work.'/staging/'.ReleasePackage::ROOT;
    }

    private function zipPath(): string
    {
        return $this->work.'/release.zip';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        $state['updated_at'] = CarbonImmutable::now()->toIso8601String();

        $tmp = $this->work.'/state.json.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->work.'/state.json');
    }

    /**
     * Runs $work while holding the update lock, so two open tabs can't run
     * the same step at once. Returns $orElse when the lock is taken.
     *
     * @template T
     * @template TElse
     *
     * @param  callable(): T  $work
     * @param  TElse  $orElse
     * @return T|TElse
     */
    private function locked(callable $work, mixed $orElse = null): mixed
    {
        if (! is_dir($this->work)) {
            mkdir($this->work, 0775, true);
        }

        $handle = fopen($this->work.'/lock', 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }

            return $orElse;
        }

        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
