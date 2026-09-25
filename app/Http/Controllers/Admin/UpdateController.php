<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Update\ReleaseFeed;
use App\Update\UpdateException;
use App\Update\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The updates page, and the step endpoint its script calls until the
 * update is done.
 *
 * The step and retry endpoints answer plain JSON rather than Inertia
 * pages, because after the swap they're served by the new version while
 * the open page still runs the old version's script. Their URLs and
 * response shape are part of the contract described on Updater.
 */
class UpdateController extends Controller
{
    public function __construct(
        private readonly Updater $updater,
        private readonly ReleaseFeed $feed,
    ) {}

    public function show(): Response
    {
        $latest = $this->feed->latest();
        $current = $this->updater->currentVersion();

        return Inertia::render('admin/Updates', [
            'current' => $current,
            'latest' => [
                'release' => $latest['release'] ? Arr::only($latest['release']->toArray(), ['version', 'published_at']) : null,
                'error' => $latest['error'],
                'checkedAt' => $latest['checkedAt'],
            ],
            'available' => $latest['release']?->newerThan($current) ?? false,
            'state' => $this->present($this->updater->state()),
            'steps' => ['update' => Updater::STEPS, 'rollback' => Updater::ROLLBACK_STEPS],
        ]);
    }

    public function check(): RedirectResponse
    {
        $this->feed->latest(fresh: true);

        return to_route('admin.updates');
    }

    public function start(Request $request): RedirectResponse
    {
        $request->validate(['version' => ['required', 'string']]);

        $release = $this->feed->latest()['release'];

        // The page offered one version; if the feed has moved on since,
        // the owner should see the new one before agreeing to it.
        if ($release === null || $release->version !== $request->string('version')->toString()) {
            return to_route('admin.updates')->withErrors(['update' => 'A different version is available now. Check the page again before updating.']);
        }

        try {
            $this->updater->start($release);
        } catch (UpdateException $e) {
            return to_route('admin.updates')->withErrors(['update' => $e->getMessage()]);
        }

        return to_route('admin.updates');
    }

    public function step(): JsonResponse
    {
        $result = $this->updater->step();

        return $this->json($result['state'], $result['busy']);
    }

    public function retry(): JsonResponse
    {
        return $this->json($this->updater->retry(), false);
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private function json(?array $state, bool $busy): JsonResponse
    {
        $response = response()->json(['state' => $this->present($state), 'busy' => $busy]);

        if (($cookie = $this->updater->bypassCookie()) !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    private function present(?array $state): ?array
    {
        return $state === null ? null : [
            ...Arr::only($state, ['status', 'mode', 'step', 'from', 'waiting', 'error', 'failed_step', 'rollback_error', 'started_at', 'finished_at']),
            'to' => $state['to']['version'] ?? null,
        ];
    }
}
