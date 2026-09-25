<?php

namespace Tests\Feature\StarDust;

use App\Models\SchemaModel;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use App\StarDust\TickPause;
use App\StarDust\TickRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * The updater pauses the tick before it swaps files, and resumes it once
 * the update is done or has rolled back (Updater::pauseSite()/finish()).
 * A schema change's own background work — StarDust's rewrite after a
 * rename, here — runs through that same tick, so it must wait out a pause
 * rather than racing the file swap, and pick back up once the pause lifts.
 */
class SchemaTickPauseTest extends TestCase
{
    use RefreshDatabase, UsesStarDust;

    private const TITLE = '10000000-0000-4000-8000-000000000001';

    public function test_a_renames_background_rewrite_waits_out_a_paused_tick()
    {
        $model = $this->createModelWithField();
        $entry = $this->writeEntry($model->stardust_model_id, ['title' => 'Hello']);

        $manager = app(SchemaManager::class);
        $renamed = ModelSchema::fromModel($model)->withFields([
            FieldSpec::fromField($model->fields()->firstOrFail())->with(['key' => 'headline']),
        ]);

        $result = $manager->save($model, $renamed);
        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertSame('renaming', $manager->states($model)[self::TITLE]);

        app(TickPause::class)->pause('update');

        $run = app(TickRunner::class)->run('manual', 10);
        $this->assertSame(TickRunner::STOP_PAUSED, $run->stop_reason);
        $this->assertSame(0, $run->rounds);

        // Still renaming, and the background rewrite itself hasn't run:
        // the raw stored payload is still under the old key. (Reads
        // already bridge to the new key regardless of the rewrite, so
        // that alone wouldn't prove the tick pause held anything back.)
        $this->assertSame('renaming', $manager->states($model)[self::TITLE]);
        $this->assertSame('Hello', $this->readEntry($entry)->fields['headline'] ?? null);
        $this->assertArrayHasKey('title', $this->storedPayload($entry));
        $this->assertArrayNotHasKey('headline', $this->storedPayload($entry));

        app(TickPause::class)->resume();
        $this->drain();

        // Resuming let the same queued rewrite run for real.
        $this->assertSame('ready', $manager->states($model)[self::TITLE]);
        $this->assertSame('Hello', $this->readEntry($entry)->fields['headline'] ?? null);
        $this->assertArrayHasKey('headline', $this->storedPayload($entry));
        $this->assertArrayNotHasKey('title', $this->storedPayload($entry));
    }

    private function createModelWithField(): SchemaModel
    {
        $schema = new ModelSchema('articles', 'Articles', fields: [
            new FieldSpec(self::TITLE, 'title', 'Title', 'text'),
        ]);

        $result = app(SchemaManager::class)->createModel($schema);
        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertNotNull($result->model);

        $this->drain();

        return $result->model;
    }
}
