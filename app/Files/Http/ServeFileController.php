<?php

namespace App\Files\Http;

use App\Files\File;
use App\Files\FileServer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServeFileController extends Controller
{
    /**
     * The name segment is only for readable URLs and is ignored; the uuid
     * alone picks the file, within the current site, and never a trashed one.
     */
    public function __invoke(Request $request, FileServer $server, string $uuid): BinaryFileResponse
    {
        $file = File::query()->where('uuid', strtolower($uuid))->first();

        abort_if($file === null || ! $server->canServe($file, $request), 404);

        return $server->respond($file, $request);
    }
}
