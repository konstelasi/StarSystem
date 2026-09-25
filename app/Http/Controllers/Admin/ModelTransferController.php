<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ReportsInvalidSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportModelRequest;
use App\Models\SchemaModel;
use App\Schema\InvalidSchemaException;
use App\Schema\SaveResult;
use App\Schema\SchemaException;
use App\Schema\SchemaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Moving a model's schema in and out as JSON, e.g. between sites or installs.
 */
class ModelTransferController extends Controller
{
    use ReportsInvalidSchema;

    public function __construct(private readonly SchemaManager $schema) {}

    public function export(SchemaModel $model): JsonResponse|RedirectResponse
    {
        if ($model->isDeleting()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => SchemaException::text('This model is being deleted.')]);

            return to_route('admin.models.index');
        }

        return response()->json($this->schema->export($model), 200, [
            'Content-Disposition' => 'attachment; filename="'.$model->slug.'.json"',
        ]);
    }

    public function import(ImportModelRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $result = $this->schema->import((string) $request->file('file')?->get(), $data['slug'] ?? null, $data['label'] ?? null);
        } catch (InvalidSchemaException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $this->failIfInvalid($result, 'file');

        if ($result->status === SaveResult::FAILED) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) $result->error()]);
        }

        return to_route('admin.models.builder', $result->model);
    }
}
