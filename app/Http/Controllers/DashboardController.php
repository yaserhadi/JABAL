<?php

namespace App\Http\Controllers;

use App\Support\Context\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request): Response
    {
        $tenant = TenantContext::getInstance()->get();
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
