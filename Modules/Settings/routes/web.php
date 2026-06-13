<?php

use Illuminate\Support\Facades\Route;

// Legacy /admin paths redirect to Platform Management app (ADR-0007).
Route::redirect('/admin/settings', '/platform/settings');
Route::redirect('/admin/audit', '/platform/audit');
