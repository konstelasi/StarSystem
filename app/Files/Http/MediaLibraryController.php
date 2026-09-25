<?php

namespace App\Files\Http;

use App\Files\UploadLimits;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class MediaLibraryController extends Controller
{
    /**
     * The page shell. Files and folders load from the JSON API, which the
     * file picker uses too, so both always behave the same.
     */
    public function __invoke(UploadLimits $limits): Response
    {
        return Inertia::render('admin/files/Index', [
            'limits' => $limits->toArray(),
        ]);
    }
}
