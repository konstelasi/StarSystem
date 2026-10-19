<?php

namespace App\Http\Controllers\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Entries\EntryManager;
use App\Files\File;
use App\Http\Controllers\Controller;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SchemaManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use StarDust\Exception\InvalidCursorException;
use StarDust\Read\Entry;

/**
 * The admin's entries: a model's actual content records.
 */
class EntryController extends Controller
{
    public function __construct(
        private readonly EntryManager $entries,
        private readonly SchemaManager $schema,
        private readonly SiteAccess $access,
    ) {}

    public function index(Request $request, SchemaModel $model): Response
    {
        return $this->listing($request, $model, trashed: false);
    }

    public function trash(Request $request, SchemaModel $model): Response
    {
        return $this->listing($request, $model, trashed: true);
    }

    /**
     * The toast offers Undo, since the entry is only one click from coming
     * back.
     */
    public function moveToTrash(Request $request, SchemaModel $model, int $entry): RedirectResponse
    {
        $found = $this->entryOr404($model, $entry);
        $this->authorize($request, $model, Ability::ENTRIES_TRASH, $found);

        if (! $this->entries->isTrashed($found)) {
            $this->entries->trash($model, $found);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Moved to trash.',
            'action' => ['label' => 'Undo', 'url' => route('admin.models.entries.restore', ['model' => $model, 'entry' => $entry], absolute: false)],
        ]);

        return to_route('admin.models.entries.index', $model);
    }

    /**
     * Back to wherever the restore came from: the trash, or the list when
     * it was the Undo of a move.
     */
    public function restore(Request $request, SchemaModel $model, int $entry): RedirectResponse
    {
        $found = $this->entryOr404($model, $entry);
        $this->authorize($request, $model, Ability::ENTRIES_RESTORE, $found);

        if ($this->entries->isTrashed($found)) {
            $this->entries->restore($model, $found);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry restored.']);

        return back(fallback: route('admin.models.entries.trash', $model));
    }

    /**
     * Deletes a trashed entry for good, with StarDust's own delete, which
     * has no restore. An active entry has to go through the trash first.
     */
    public function destroy(Request $request, SchemaModel $model, int $entry): RedirectResponse
    {
        $found = $this->entryOr404($model, $entry);
        $this->authorize($request, $model, Ability::ENTRIES_DELETE, $found);

        if (! $this->entries->isTrashed($found)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Move it to the trash first.']);

            return to_route('admin.models.entries.index', $model);
        }

        $this->entries->delete($model, $found);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry deleted for good.']);

        return to_route('admin.models.entries.trash', $model);
    }

    /**
     * One page at a time. "Load more" asks for the next page with `cursor`
     * and the client appends it, since `entries` is a merge prop.
     */
    private function listing(Request $request, SchemaModel $model, bool $trashed): Response
    {
        $columns = $this->listColumns($model);
        $keys = $columns->pluck('key')->all();

        $cursor = $request->query('cursor');

        // "Only mine" asks for the entries the signed-in account made.
        $mine = $request->boolean('mine');

        try {
            $page = $this->entries->page($model, is_string($cursor) ? $cursor : null, $trashed, $mine ? $request->user()?->id : null);
        } catch (InvalidCursorException) {
            abort(404);
        }

        // Full field specs, so the list can show each value by its type.
        $specs = collect(ModelSchema::fromModel($model)->fields)->keyBy(fn (FieldSpec $spec) => $spec->key);
        $fileNames = $this->fileNames($columns, $page->rows);
        $authors = $this->authorNames($page->rows);

        return Inertia::render('admin/entries/Index', [
            'model' => ['id' => $model->id, 'label' => $model->label],
            'view' => $trashed ? 'trash' : 'active',
            'hasFields' => $model->fields->isNotEmpty(),
            'columns' => $columns->map(fn (SchemaField $field) => $specs[$field->key]->toJson())->values(),
            'entries' => Inertia::merge(collect($page->rows)->map(fn (Entry $entry) => [
                'id' => $entry->id,
                'values' => Arr::only($entry->fields, $keys),
                // Per row rather than per page, so "Load more" brings the
                // names of the files it appends.
                'file_names' => (object) Arr::only($fileNames, $this->fileUuids($columns, $entry)),
                'created_at' => $entry->createdAt->format(DATE_ATOM),
                'trashed_at' => $this->entries->trashedAt($entry)?->format(DATE_ATOM),
                'author' => $this->author($this->entries->createdBy($entry), $authors),
                // What this account may do to this entry, which depends on who
                // made it. The server asks again when the action arrives.
                'can' => $this->can($request, $model, $entry),
            ])->values()),
            'mine' => $mine,
            'nextCursor' => $page->nextCursor?->opaque,
            'trashDays' => config()->integer('starsystem.entries.trash_days'),
        ]);
    }

    public function create(SchemaModel $model): Response
    {
        return Inertia::render('admin/entries/Create', [
            'model' => ['id' => $model->id, 'label' => $model->label],
            'schema' => $this->schema->export($model),
        ]);
    }

    public function store(Request $request, SchemaModel $model): RedirectResponse
    {
        $fields = $this->validated($request, $model);

        $this->entries->create($model, $fields, $request->user()?->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry created.']);

        return to_route('admin.models.entries.index', $model);
    }

    /**
     * Values are sent as stored, keyed by the model's field keys only; the
     * form turns them into what each input expects (see formValue() in
     * resources/js/fields).
     */
    public function edit(Request $request, SchemaModel $model, int $entry): Response|RedirectResponse
    {
        $found = $this->entryOr404($model, $entry);

        if ($this->entries->isTrashed($found)) {
            return $this->restoreFirst($model);
        }

        $savedBy = $this->entries->updatedBy($found);
        $names = $this->userNames([$this->entries->createdBy($found), $savedBy]);

        return Inertia::render('admin/entries/Edit', [
            'model' => ['id' => $model->id, 'label' => $model->label],
            'schema' => $this->schema->export($model),
            'entry' => [
                'id' => $found->id,
                'created_at' => $found->createdAt->format(DATE_ATOM),
                'author' => $this->author($this->entries->createdBy($found), $names),
                'saved_by' => $savedBy === null ? null : ($names[$savedBy] ?? null),
                // Whether this account may save changes to this entry.
                'can_update' => $this->access->mayOnEntry($this->user($request), Ability::ENTRIES_UPDATE, $this->entries->createdBy($found), $model->id),
            ],
            'values' => (object) Arr::only($found->fields, $model->fields->pluck('key')->all()),
        ]);
    }

    public function update(Request $request, SchemaModel $model, int $entry): RedirectResponse
    {
        $found = $this->entryOr404($model, $entry);
        $this->authorize($request, $model, Ability::ENTRIES_UPDATE, $found);

        if ($this->entries->isTrashed($found)) {
            return $this->restoreFirst($model);
        }

        $this->entries->update($model, $entry, $this->validated($request, $model), $request->user()?->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry saved.']);

        return to_route('admin.models.entries.index', $model);
    }

    /**
     * A trashed entry is restored before it can be edited, so an edit never
     * lands on something about to be purged.
     */
    private function restoreFirst(SchemaModel $model): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Restore this entry to edit it.']);

        return to_route('admin.models.entries.trash', $model);
    }

    /**
     * Refuses the action unless this account may do it to this entry: it
     * holds the ability for every entry of this model, or its own form for
     * one it made.
     */
    private function authorize(Request $request, SchemaModel $model, string $ability, Entry $entry): void
    {
        abort_unless($this->access->mayOnEntry($this->user($request), $ability, $this->entries->createdBy($entry), $model->id), 403);
    }

    /**
     * @return array{update: bool, trash: bool, restore: bool, delete: bool}
     */
    private function can(Request $request, SchemaModel $model, Entry $entry): array
    {
        $user = $this->user($request);
        $author = $this->entries->createdBy($entry);

        return [
            'update' => $this->access->mayOnEntry($user, Ability::ENTRIES_UPDATE, $author, $model->id),
            'trash' => $this->access->mayOnEntry($user, Ability::ENTRIES_TRASH, $author, $model->id),
            'restore' => $this->access->mayOnEntry($user, Ability::ENTRIES_RESTORE, $author, $model->id),
            'delete' => $this->access->mayOnEntry($user, Ability::ENTRIES_DELETE, $author, $model->id),
        ];
    }

    /**
     * The names of the accounts that made these entries, by user id. An
     * account that has since been deleted is missing.
     *
     * @param  list<Entry>  $entries
     * @return array<int, string>
     */
    private function authorNames(array $entries): array
    {
        return $this->userNames(array_map(fn (Entry $entry) => $this->entries->createdBy($entry), $entries));
    }

    /**
     * The names of these accounts by user id, ignoring ids that are null.
     *
     * @param  list<int|null>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> */
        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Who made an entry, for the page to word: a name; a former member,
     * whose account is gone; or unknown, for an entry written before
     * authors were kept.
     *
     * @param  array<int, string>  $names
     * @return array{state: 'known'|'former'|'unknown', name: string|null}
     */
    private function author(?int $id, array $names): array
    {
        if ($id === null) {
            return ['state' => 'unknown', 'name' => null];
        }

        return isset($names[$id])
            ? ['state' => 'known', 'name' => $names[$id]]
            : ['state' => 'former', 'name' => null];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * EntryManager::get() is already null for another model's, another
     * site's or a deleted entry.
     */
    private function entryOr404(SchemaModel $model, int $id): Entry
    {
        return $this->entries->get($model, $id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, SchemaModel $model): array
    {
        $rules = [];
        foreach ($this->entries->rulesFor($model) as $key => $fieldRules) {
            $rules["fields.{$key}"] = $fieldRules;
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        return $this->entries->normalizeFields($model, $validated['fields'] ?? []);
    }

    /**
     * The display names of every file the page's file columns point at,
     * by uuid. File's site scope keeps another site's files out, and a
     * trashed or purged file is simply missing.
     *
     * @param  Collection<int, SchemaField>  $columns
     * @param  list<Entry>  $rows
     * @return array<string, string>
     */
    private function fileNames(Collection $columns, array $rows): array
    {
        $uuids = collect($rows)->flatMap(fn (Entry $entry) => $this->fileUuids($columns, $entry))->unique()->values();

        if ($uuids->isEmpty()) {
            return [];
        }

        /** @var array<string, string> */
        return File::query()->whereIn('uuid', $uuids)->pluck('original_name', 'uuid')->all();
    }

    /**
     * The file uuids an entry holds in the list's file columns, one or many
     * per field.
     *
     * @param  Collection<int, SchemaField>  $columns
     * @return list<string>
     */
    private function fileUuids(Collection $columns, Entry $entry): array
    {
        return array_values($columns->where('type', 'file')
            ->flatMap(fn (SchemaField $field) => (array) ($entry->fields[$field->key] ?? []))
            ->filter(fn ($uuid) => is_string($uuid) && $uuid !== '')
            ->map(fn (string $uuid) => strtolower($uuid))
            ->all());
    }

    /**
     * The fields shown as list columns: the ones the builder marked, or the
     * first three when nobody has, so a model built before that flag
     * existed still shows something.
     *
     * @return Collection<int, SchemaField>
     */
    private function listColumns(SchemaModel $model): Collection
    {
        $fields = $model->fields;
        $shown = $fields->where('shown_in_list', true)->values();

        return $shown->isNotEmpty() ? $shown : $fields->take(3)->values();
    }
}
