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

        return Inertia::render('admin/models/Builder', [
            'model' => ['id' => $model->id],
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
    public function preview(Request $request, SchemaModel $model): Response
    {
        return Inertia::render('admin/models/Builder', [
            'preview' => $this->schema->preview($model, $this->desiredSchema($request)),
        ]);
    }

    /**
     * The two-step save, step two. `saved` is false, with the preview's
     * errors, when the diff has errors or the batch failed partway; the
     * client only treats a true `saved` as done.
     */
    public function save(Request $request, SchemaModel $model): Response
    {
        $result = $this->schema->save($model, $this->desiredSchema($request));

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
}
