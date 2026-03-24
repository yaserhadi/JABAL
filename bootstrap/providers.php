<?php

return [
    // Stancl tenancy listeners (BootstrapTenancy, etc.) — register before AppServiceProvider so
    // TenancyInitialized listeners run in a sensible order alongside SetSpatiePermissionsTeamId.
    App\Providers\TenancyServiceProvider::class,
    App\Providers\AppServiceProvider::class,
];
