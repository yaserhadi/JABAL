<?php

use Illuminate\Support\Facades\Route;

// Legacy /admin paths redirect to Platform Management app (ADR-0007 / BK-125 Wave 5).
Route::get('/admin/settings', function () {
    return redirect()->route('platform.settings.index');
});
Route::get('/admin/audit', function () {
    return redirect()->route('platform.audit.index');
});
