<?php

namespace App\Http\Controllers\Admin;

use App\Health\HealthReport;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
    public function __invoke(HealthReport $report): Response
    {
        return Inertia::render('admin/Health', $report->toArray());
    }
}
