<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\StarDust\TickRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tick for hosts that offer a scheduled URL fetch but no cron.
 *
 * A public URL that triggers database work is an amplification handle for
 * anyone who finds it, so it needs the secret, compared in constant time,
 * and it is off entirely while no secret is configured.
 */
class TickController extends Controller
{
    public function __invoke(Request $request, TickRunner $runner): JsonResponse
    {
        $secret = (string) config('stardust.tick.secret');
        $given = (string) ($request->header('X-Tick-Key') ?? $request->query('key', ''));

        abort_if($secret === '' || ! hash_equals($secret, $given), 404);

        abort_if(config('stardust.profile') === 'server', 409, 'The server profile runs the StarDust daemons instead.');

        // Finish the tick even if the fetch service gives up waiting.
        ignore_user_abort(true);

        $run = $runner->run('url', (int) config('stardust.tick.url_budget'));

        // A paused tick is a success: the fetch service did its job, and
        // alerting the owner about it would only cause worry mid-update.
        return response()->json([
            'ok' => $run->error === null,
            'paused' => $run->stop_reason === TickRunner::STOP_PAUSED,
            'rounds' => $run->rounds,
            'elapsed_seconds' => $run->elapsed_seconds,
            'stop_reason' => $run->stop_reason,
        ], $run->error === null ? 200 : 500);
    }
}
