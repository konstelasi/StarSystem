<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ReportsInvalidSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreModelRequest;
use App\Models\SchemaModel;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaException;
use App\Schema\SchemaManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The site's models list: what the builder edits are, in one place.
 */
class ModelController extends Controller
{
    use ReportsInvalidSchema;

    public function __construct(private readonly SchemaManager $schema) {}

    public function index(): Response
    {
        $this->schema->forgetPurgedModels();

        $models = SchemaModel::query()
            ->withCount('fields')
            ->orderBy('label')
            ->get()
            ->map(fn (SchemaModel $model) => $this->row($model));

        return Inertia::render('admin/models/Index', [
            'models' => $models,
        ]);
    }

    public function store(StoreModelRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $result = $this->schema->createModel(new ModelSchema($data['slug'], $data['label'], group: $data['group'] ?? null));

        $this->failIfInvalid($result, 'slug');

        if ($result->status === SaveResult::FAILED) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) $result->error()]);
        }

        return to_route('admin.models.builder', $result->model);
    }

    public function destroy(SchemaModel $model): RedirectResponse
    {
        try {
            $this->schema->deleteModel($model);
        } catch (SchemaException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('admin.models.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$model->label} is being deleted."]);

        return to_route('admin.models.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SchemaModel $model): array
    {
        return [
            'id' => $model->id,
            'slug' => $model->slug,
            'label' => $model->label,
            'group' => $model->group,
            'fields_count' => $model->fields_count,
            'status' => $model->status,
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}
