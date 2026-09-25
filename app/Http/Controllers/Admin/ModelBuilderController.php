<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchemaModel;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\InvalidSchemaException;
use App\Schema\ModelSchema;
use App\Schema\SchemaException;
use App\Schema\SchemaManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drag-and-drop model builder, backed by the real schema layer.
 *
 * The page always edits one model: the first one not being deleted, or a
 * fresh "Untitled model" created the first time the page is opened on a
 * site with none yet. Choosing which model to edit, for a site with more
 * than one, is future work (see stream-b-improvement-ideas.md).
 */
class ModelBuilderController extends Controller
{
    public function __construct(
        private readonly SchemaManager $schema,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {}

    public function show(): Response
    {
        $model = $this->currentModel();

        return Inertia::render('admin/models/Builder', [
            'fieldTypes' => $this->fieldTypes->toArray(),
            'schema' => $this->schema->export($model),
            'states' => $this->schema->states($model),
        ]);
    }

    /**
     * The two-step save, step one: what saving the posted schema would do,
     * diffed against the model's stored schema. A partial Inertia reload,
     * so it only touches the `preview` prop and leaves the client's
     * in-progress schema alone.
     */
    public function preview(Request $request): Response
    {
        return Inertia::render('admin/models/Builder', [
            'preview' => $this->schema->preview($this->currentModel(), $this->desiredSchema($request)),
        ]);
    }

    /**
     * The two-step save, step two. `saved` is false, with the preview's
     * errors, when the diff has errors or the batch failed partway; the
     * client only treats a true `saved` as done.
     */
    public function save(Request $request): Response
    {
        $result = $this->schema->save($this->currentModel(), $this->desiredSchema($request));

        return Inertia::render('admin/models/Builder', [
            'saved' => $result->succeeded(),
            'preview' => $result->preview,
        ]);
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

    /**
     * The model this page edits.
     */
    private function currentModel(): SchemaModel
    {
        $this->schema->forgetPurgedModels();

        $model = SchemaModel::query()->where('status', '!=', SchemaModel::DELETING)->orderBy('id')->first();

        if ($model !== null) {
            return $model;
        }

        $result = $this->schema->createModel(new ModelSchema('untitled', 'Untitled model'));

        return $result->model ?? throw new SchemaException('Could not create the starter model: '.($result->error() ?? 'unknown error.'));
    }
}
