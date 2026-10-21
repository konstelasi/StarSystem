<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchemaModel;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\InvalidSchemaException;
use App\Schema\ModelSchema;
use App\Schema\SchemaException;
use App\Schema\SchemaManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drag-and-drop model builder, backed by the real schema layer.
 */
class ModelBuilderController extends Controller
{
    public function __construct(
        private readonly SchemaManager $schema,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {}

    /**
     * A stale tab or the back button pointing at a model that has since
     * been deleted is a normal case, not an error page.
     */
    public function show(SchemaModel $model): Response|RedirectResponse
    {
        if ($model->isDeleting()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => SchemaException::text('This model is being deleted.')]);

            return to_route('admin.models.index');
        }

        // Closures, so the builder's poll for `states` builds only that.
        return Inertia::render('admin/models/Builder', [
            'model' => fn () => ['id' => $model->id, 'schema_rev' => $model->schema_rev],
            'fieldTypes' => fn () => $this->fieldTypes->toArray(),
            'schema' => fn () => $this->schema->export($model),
            'states' => fn () => $this->schema->states($model),
            'otherSlugs' => fn () => SchemaModel::query()->whereKeyNot($model->id)->pluck('slug'),
            'unfinished' => fn () => $this->unfinished($model),
        ]);
    }

    /**
     * The two-step save, step one: what saving the posted schema would do,
     * diffed against the model's stored schema. A partial Inertia reload,
     * so it only touches the `preview` prop and leaves the client's
     * in-progress schema alone.
     */
    public function preview(Request $request, SchemaModel $model): Response
    {
        return Inertia::render('admin/models/Builder', [
            'preview' => $this->schema->preview($model, $this->desiredSchema($request), $this->openedRev($request), $request->user()),
        ]);
    }

    /**
     * The two-step save, step two. `saved` is false, with the preview's
     * errors, when the diff has errors or the batch failed partway; the
     * client only treats a true `saved` as done. `rev` is the revision the
     * tab's next save must send, `unfinished` is set when the batch failed
     * partway, for the builder's "finish the save" banner, and `states`
     * shows the badges of fields the save set working.
     */
    public function save(Request $request, SchemaModel $model): Response
    {
        $result = $this->schema->save($model, $this->desiredSchema($request), $this->openedRev($request), $request->user());

        return Inertia::render('admin/models/Builder', [
            'saved' => $result->succeeded(),
            'preview' => $result->preview,
            'rev' => $result->model->schema_rev ?? $model->schema_rev,
            'unfinished' => $this->unfinished($model),
            'states' => $this->schema->states($model),
        ]);
    }

    /**
     * Finishes a save that stopped partway. Shared hosting has no shell for
     * `starsystem:schema:retry`, so this is the owner's only way out. It
     * redirects to the builder either way, so the page reloads the schema
     * as it now stands.
     */
    public function retry(SchemaModel $model): RedirectResponse
    {
        $unfinished = $this->schema->unfinished($model);

        if ($unfinished !== null) {
            $result = $this->schema->retry($unfinished['batch']);

            Inertia::flash('toast', $result->succeeded()
                ? ['type' => 'success', 'message' => SchemaException::text('The last save finished.')]
                : ['type' => 'error', 'message' => (string) $result->error()]);
        }

        return to_route('admin.models.builder', $model);
    }

    /**
     * @return array{error: string}|null
     */
    private function unfinished(SchemaModel $model): ?array
    {
        $unfinished = $this->schema->unfinished($model);

        return $unfinished === null ? null : ['error' => $unfinished['error']];
    }

    /**
     * The schema_rev the tab opened, so a save made from an outdated
     * schema is refused instead of undoing the newer one.
     */
    private function openedRev(Request $request): int
    {
        return (int) $request->validate(['rev' => ['required', 'integer', 'min:0']])['rev'];
    }

    /**
     * The rest of ModelSchema::fromJson()'s checks (a missing version, an
     * unnamed model, and so on) are shapes the builder itself never sends,
     * so a client bug or a hand-edited JSON tab is the only way to hit
     * them; reported as a form error rather than a 500.
     */
    private function desiredSchema(Request $request): ModelSchema
    {
        $incoming = $request->validate(['schema' => ['required', 'array']])['schema'];

        try {
            return ModelSchema::fromJson($incoming);
        } catch (InvalidSchemaException $e) {
            throw ValidationException::withMessages(['schema' => $e->getMessage()]);
        }
    }
}
