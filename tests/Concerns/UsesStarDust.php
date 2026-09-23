<?php

namespace Tests\Concerns;

use App\Models\SchemaModel;
use App\Models\Site;
use App\Sites\CurrentSite;
use App\StarDust\StarDustService;
use App\StarDust\TickRunner;
use Illuminate\Support\Str;
use StarDust\Read\Entry;
use StarDust\Write\EntryPayload;

/**
 * For tests that drive a real StarDust.
 *
 * RefreshDatabase rolls back Laravel's connection only. StarDust writes on
 * its own PDO and commits, so its rows outlive each test. Every test
 * therefore works as a new site: ss_sites ids are never reused within a
 * run (a rolled-back auto-increment value is not handed out again), so
 * each test gets its own StarDust tenant and model names never collide.
 */
trait UsesStarDust
{
    protected Site $site;

    protected function setUpUsesStarDust(): void
    {
        $this->artisan('stardust:bootstrap')->assertSuccessful();

        $this->site = Site::create(['name' => 'StarDust test', 'domains' => [Str::lower(Str::random(12)).'.test']]);
        app(CurrentSite::class)->set($this->site);
    }

    protected function stardust(): StarDustService
    {
        return app(StarDustService::class);
    }

    /**
     * Runs ticks until StarDust reports it has nothing left to do.
     */
    protected function drain(int $maxTicks = 6): void
    {
        for ($i = 0; $i < $maxTicks; $i++) {
            $run = app(TickRunner::class)->run('manual', 10);

            $this->assertNull($run->error, "The tick failed: {$run->error}");

            if ($run->stop_reason === 'idle' && $run->rounds <= 1) {
                return;
            }
        }
    }

    /**
     * A StarDust model plus the ss_models row that makes the site own it.
     */
    protected function registerModel(string $slug = 'articles'): SchemaModel
    {
        return SchemaModel::create([
            'stardust_model_id' => $this->stardust()->createModel($slug),
            'slug' => $slug,
            'label' => Str::headline($slug),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function writeEntry(int $stardustModelId, array $fields): int
    {
        return $this->stardust()->engine()
            ->write(new EntryPayload($this->site->id, $stardustModelId, $fields))
            ->entryId;
    }

    protected function readEntry(int $entryId): Entry
    {
        $entry = $this->stardust()->engine()->get($this->site->id, $entryId);
        $this->assertNotNull($entry);

        return $entry;
    }

    /**
     * The raw stored payload, bypassing StarDust's read-time bridging, to
     * prove a background rewrite really happened.
     *
     * @return array<string, mixed>
     */
    protected function storedPayload(int $entryId): array
    {
        $statement = $this->stardust()->engine()->pdo()->prepare('SELECT fields FROM entry_data WHERE id = ?');
        $statement->execute([$entryId]);

        return json_decode((string) $statement->fetchColumn(), true);
    }
}
