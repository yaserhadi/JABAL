<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BK-125 Wave 5: authenticated Platform home (/dashboard).
 */
class DashboardController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Platform/Dashboard', [
            'entryPlane' => 'platform_operator',
        ]);
    }
}
