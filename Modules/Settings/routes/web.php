<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\SettingsController;

// Legacy /admin paths redirect to Platform Management app (ADR-0007).
Route::redirect('/admin/settings', '/platform/settings');
Route::redirect('/admin/audit', '/platform/audit');
