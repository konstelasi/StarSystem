<?php

namespace App\Files\Http;

use App\Files\File;
use App\Files\FileKind;
use App\Files\FileRejected;
use App\Files\FileStore;
use App\Files\FolderPath;
use App\Files\Http\Rules\AllowedUpload;
use App\Files\Http\Rules\Folder;
use App\Files\UploadLimits;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The media library's JSON API, also used by <FilePicker>. Every query
 * goes through File's site scope, so another site's uuid is a 404 here
 * exactly as on the serving route.
 */
class FileApiController extends Controller
{
    public function __construct(private readonly FileStore $store) {}

    /**
     * Newest first, cursor-paginated so paging stays fast and stable while
     * files are uploaded. Without `folder` every folder is searched; with
     * `folder=` only the root. The first page also carries every folder
     * path and the upload limits, so one request fills the whole screen.
     */
    public function index(Request $request, UploadLimits $limits): JsonResponse
    {
        $validated = $request->validate([
            'folder' => ['nullable', new Folder],
            'q' => ['nullable', 'string', 'max:255'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::in(FileKind::ALL)],
            'uuids' => ['nullable', 'array', 'max:100'],
            'uuids.*' => ['uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $query = File::query()->with('uploader:id,name')->orderByDesc('id');

        if ($request->has('folder') && empty($validated['uuids'])) {
            $query->where('folder', FolderPath::normalize($validated['folder'] ?? ''));
        }

        if (! empty($validated['q'])) {
            $query->where('original_name', 'like', '%'.addcslashes($validated['q'], '\\%_').'%');
        }

        if (! empty($validated['kinds'])) {
            FileKind::constrain($query, array_values($validated['kinds']));
        }

        if (! empty($validated['uuids'])) {
            $query->whereIn('uuid', array_map('strtolower', $validated['uuids']));
        }

        $page = $query->cursorPaginate((int) ($validated['per_page'] ?? 48));
        $first = empty($validated['cursor']);

        return response()->json([
            'data' => FileResource::collection($page->items())->resolve($request),
            'nextCursor' => $page->nextCursor()?->encode(),
            'folders' => $first ? $this->folders() : null,
            'limits' => $first ? $limits->toArray() : null,
        ]);
    }

    /**
     * Stores one or more files. All are checked before any is stored, so a
     * file of the wrong type or size never leaves half the batch behind; a
     * write failure midway keeps the files stored before it.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [new AllowedUpload($this->store)],
            'folder' => ['nullable', new Folder],
        ], [
            'files.required' => 'Choose at least one file to upload.',
        ]);

        /** @var list<UploadedFile> $uploads */
        $uploads = $request->file('files');
        $user = $request->user();
        $stored = [];

        foreach ($uploads as $i => $upload) {
            try {
                $file = $this->store->store($upload, (string) $request->input('folder', ''), $user?->id);
            } catch (FileRejected $e) {
                throw ValidationException::withMessages(["files.{$i}" => $e->getMessage()]);
            }

            $stored[] = $file->setRelation('uploader', $user);
        }

        return response()->json([
            'data' => FileResource::collection($stored)->resolve($request),
        ], 201);
    }

    /**
     * Renames, moves, or sets the alt text; any combination in one call.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $file = $this->find($uuid);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'not_regex:/[\/\\\\]/'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'folder' => ['sometimes', new Folder],
        ], [
            'name.required' => 'The name can\'t be empty.',
            'name.not_regex' => 'The name can\'t contain slashes. Use "Move" to change folders.',
        ]);

        if (array_key_exists('name', $validated)) {
            $this->store->rename($file, $validated['name']);
        }

        if (array_key_exists('alt', $validated)) {
            $this->store->describe($file, $validated['alt']);
        }

        if (array_key_exists('folder', $validated)) {
            $this->store->move($file, (string) $validated['folder']);
        }

        return response()->json(['data' => (new FileResource($file->load('uploader:id,name')))->resolve($request)]);
    }

    public function destroy(string $uuid): Response
    {
        $this->store->delete($this->find($uuid));

        return response()->noContent();
    }

    private function find(string $uuid): File
    {
        return File::query()->where('uuid', strtolower($uuid))->firstOrFail();
    }

    /**
     * Every folder path in use, with the parents of nested ones, since a
     * virtual folder exists only while a file is in it or below it.
     *
     * @return list<string>
     */
    private function folders(): array
    {
        $paths = [];

        foreach (File::query()->where('folder', '!=', '')->distinct()->pluck('folder') as $folder) {
            $segments = explode('/', (string) $folder);

            for ($i = 1; $i <= count($segments); $i++) {
                $paths[implode('/', array_slice($segments, 0, $i))] = true;
            }
        }

        $paths = array_map('strval', array_keys($paths));
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }
}
