<?php

namespace Tests\Feature\StarDust;

use App\Models\SchemaModel;
use App\Models\Site;
use App\Sites\CurrentSite;
use App\StarDust\FieldConflictException;
use App\StarDust\NotOwnedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class SchemaOperationsTest extends TestCase
{
    use RefreshDatabase, UsesStarDust;

    public function test_another_sites_stardust_model_is_refused()
    {
        $theirs = $this->registerModel('articles');

        $this->asNewSite();

        $this->assertRefused(fn () => $this->stardust()->defineField($theirs->stardust_model_id, 'title', 'string', false));
        $this->assertRefused(fn () => $this->stardust()->fieldStates($theirs->stardust_model_id));
        $this->assertRefused(fn () => $this->stardust()->deleteModel($theirs->stardust_model_id));
    }

    public function test_an_ss_models_row_pointing_at_another_sites_model_is_refused()
    {
        $theirs = $this->stardust()->createModel('articles');

        $this->asNewSite();
        // A forged row: this site claims the other site's StarDust model.
        SchemaModel::create(['stardust_model_id' => $theirs, 'slug' => 'forged', 'label' => 'Forged']);

        $this->assertRefused(fn () => $this->stardust()->defineField($theirs, 'title', 'string', false));
        $this->assertRefused(fn () => $this->stardust()->renameModel($theirs, 'stolen'));
        $this->assertRefused(fn () => $this->stardust()->fieldStates($theirs));
    }

    public function test_a_stardust_model_the_site_never_registered_is_refused()
    {
        $orphan = $this->stardust()->createModel('orphan');

        $this->assertRefused(fn () => $this->stardust()->defineField($orphan, 'title', 'string', false));
    }

    public function test_a_field_of_another_model_is_refused()
    {
        $articles = $this->registerModel('articles');
        $pages = $this->registerModel('pages');
        $title = $this->stardust()->defineField($articles->stardust_model_id, 'title', 'string', false);

        $this->assertRefused(fn () => $this->stardust()->renameField($pages->stardust_model_id, $title->fieldId, 'heading'));
        $this->assertFalse($this->stardust()->deleteField($pages->stardust_model_id, $title->fieldId));
        $this->assertSame('title', $this->stardust()->describeModel($articles->stardust_model_id)?->field('title')?->name);
    }

    public function test_define_field_is_get_or_create_and_catches_a_type_mismatch()
    {
        $model = $this->registerModel()->stardust_model_id;

        $first = $this->stardust()->defineField($model, 'price', 'numeric', true);
        $again = $this->stardust()->defineField($model, 'price', 'numeric', true);

        $this->assertSame($first->fieldId, $again->fieldId);

        $this->expectException(FieldConflictException::class);
        $this->stardust()->defineField($model, 'price', 'int', true);
    }

    public function test_field_states_follow_a_rename_until_the_tick_finishes_it()
    {
        $model = $this->registerModel()->stardust_model_id;
        $field = $this->stardust()->defineField($model, 'title', 'string', false);
        $this->writeEntry($model, ['title' => 'Hello']);

        $this->stardust()->renameField($model, $field->fieldId, 'headline');

        $state = $this->stardust()->fieldStates($model)[$field->fieldId];
        $this->assertSame('renaming', $state['state']);
        $this->assertSame('title', $state['previous_name']);

        $this->drain();

        $state = $this->stardust()->fieldStates($model)[$field->fieldId];
        $this->assertSame('ready', $state['state']);
        $this->assertNull($state['previous_name']);
    }

    public function test_field_states_tell_a_retype_from_a_promotion()
    {
        $model = $this->registerModel()->stardust_model_id;
        $count = $this->stardust()->defineField($model, 'count', 'string', true);
        $note = $this->stardust()->defineField($model, 'note', 'string', false);
        $this->writeEntry($model, ['count' => '12', 'note' => 'x']);
        $this->drain();

        $this->assertSame('ready', $this->stardust()->fieldStates($model)[$count->fieldId]['state']);

        $this->stardust()->retypeField($model, $count->fieldId, 'int');
        $this->stardust()->promoteField($model, $note->fieldId);

        $states = $this->stardust()->fieldStates($model);
        $this->assertSame('retyping', $states[$count->fieldId]['state']);
        $this->assertSame('indexing', $states[$note->fieldId]['state']);

        $this->drain();

        $states = $this->stardust()->fieldStates($model);
        $this->assertSame('ready', $states[$count->fieldId]['state']);
        $this->assertSame('int', $states[$count->fieldId]['declared_type']);
        $this->assertTrue($states[$count->fieldId]['indexed']);
        $this->assertSame('ready', $states[$note->fieldId]['state']);
        $this->assertTrue($states[$note->fieldId]['indexed']);
    }

    public function test_demotion_is_immediate()
    {
        $model = $this->registerModel()->stardust_model_id;
        $field = $this->stardust()->defineField($model, 'sku', 'string', true);
        $this->writeEntry($model, ['sku' => 'A-1']);
        $this->drain();

        $this->stardust()->demoteField($model, $field->fieldId);

        $state = $this->stardust()->fieldStates($model)[$field->fieldId];
        $this->assertSame('ready', $state['state']);
        $this->assertFalse($state['filterable']);
        $this->assertFalse($state['indexed']);
    }

    public function test_a_deleted_field_stays_listed_as_deleting_until_the_purge()
    {
        $model = $this->registerModel()->stardust_model_id;
        $field = $this->stardust()->defineField($model, 'body', 'string', false);
        $this->writeEntry($model, ['body' => 'Text']);

        $this->assertTrue($this->stardust()->deleteField($model, $field->fieldId));
        $this->assertFalse($this->stardust()->deleteField($model, $field->fieldId), 'A repeated delete is a no-op.');

        $this->assertNull($this->stardust()->describeModel($model)?->field('body'));
        $this->assertSame('deleting', $this->stardust()->fieldStates($model)[$field->fieldId]['state']);

        $this->drain();

        $this->assertArrayNotHasKey($field->fieldId, $this->stardust()->fieldStates($model));
    }

    public function test_a_deleted_model_is_purged_by_the_tick()
    {
        $model = $this->registerModel();
        $this->stardust()->defineField($model->stardust_model_id, 'title', 'string', false);
        $this->writeEntry($model->stardust_model_id, ['title' => 'Bye']);

        $this->assertTrue($this->stardust()->deleteModel($model->stardust_model_id));
        $this->assertFalse($this->stardust()->deleteModel($model->stardust_model_id));
        $this->assertFalse($this->stardust()->modelPurged($model->stardust_model_id));

        $this->drain();

        $this->assertTrue($this->stardust()->modelPurged($model->stardust_model_id));
    }

    private function asNewSite(): Site
    {
        $site = Site::create(['name' => 'Other', 'domains' => [Str::lower(Str::random(12)).'.test']]);
        app(CurrentSite::class)->set($site);

        return $site;
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
        } catch (NotOwnedException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The call was not refused.');
    }
}
