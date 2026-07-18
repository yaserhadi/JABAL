<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant storage mode (deployment default)
    |--------------------------------------------------------------------------
    |
    | Maps to TENANCY_MODE in .env. See ADR-0007 Appendix A for strategy.
    |
    | shared_db           — jabal_tenant_shared; tenant_id on tenant-owned rows (BelongsToTenant)
    | database_per_tenant — dedicated database per tenant when isolation_level=database
    | schema_per_tenant   — PostgreSQL schema per tenant when isolation_level=schema
    |
    | Domain code must use TenantStorageResolver + tenant context, not hardcoded connections
    | or service-level where('tenant_id'). Core Realignment Stage 5+ enables non-shared runtime provisioning.
    |
    */

    'mode' => env('TENANCY_MODE', 'shared_db'),

    'identification' => env('TENANCY_IDENTIFICATION', 'path'), // deprecated alias — prefer tenancy_addressing.profile (BK-073)

    'shared_connection' => env('TENANCY_SHARED_DB_CONNECTION', 'tenant'),

    'db_creation_mode' => env('TENANCY_DB_CREATION_MODE', 'manual'),

    'allow_database_per_tenant' => env('TENANCY_ALLOW_DATABASE_PER_TENANT', true),

    'allow_schema_per_tenant' => env('TENANCY_ALLOW_SCHEMA_PER_TENANT', false),

    'default_isolation_level' => env('TENANCY_DEFAULT_ISOLATION', 'shared'),

];
