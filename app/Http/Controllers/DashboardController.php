<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PHASE 2: Uses Stancl tenancy() for tenant context.
 */
class DashboardController extends Controller
{
    /**
     * Display the dashboard.
     */
    public function index(Request $request): Response
    {
        $tenant = tenancy()->tenant;
        $user = $request->user();

        $tenantData = null;
        if ($tenant) {
            $tenantData = [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type,
            ];
        }

        return Inertia::render('Dashboard', [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'tenant' => $tenantData,
        ]);
    }
}
