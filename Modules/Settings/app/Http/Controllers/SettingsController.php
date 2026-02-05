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

        return redirect()->route('settings.index')->with('message', 'Setting updated.');
    }
}
