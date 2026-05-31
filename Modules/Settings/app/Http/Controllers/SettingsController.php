<?php

namespace Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Settings\Services\PlatformSettingsService;

class SettingsController extends Controller
{
    public function __construct(
        private readonly PlatformSettingsService $settings
    ) {}

    /**
     * List platform settings (admin / authenticated).
     */
    public function index()
    {
        $settings = $this->settings->getGroup('general');

        return view('settings::index', ['settings' => $settings]);
    }

    /**
     * Update a setting by key.
     */
    public function update(Request $request, string $key): RedirectResponse
    {
        $request->validate(['value' => 'nullable|string']);

        $this->settings->set($key, $request->input('value'));

        $indexRoute = $request->routeIs('platform.*')
            ? 'platform.settings.index'
            : 'settings.index';

        return redirect()->route($indexRoute)->with('message', 'Setting updated.');
    }

    /**
     * Bulk update multiple settings (for Inertia Vue form).
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => 'nullable|string|max:255',
            'default_isolation' => 'nullable|string|in:shared,schema,database',
            'maintenance_mode' => 'nullable|boolean',
            'registration_enabled' => 'nullable|boolean',
            'maintenance_message' => 'nullable|string',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $this->settings->set($key, $value);
            }
        }

        return redirect()->back()->with('success', 'Settings updated.');
    }
}
