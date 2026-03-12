# Tenant Migrations (Shared Tenant DB)

**Path:** `database/migrations/tenant/`

**Purpose:** All tenant-owned table migrations for the shared tenant DB (`jabal_tenant_shared`) MUST live here, NOT inside module migration folders.

**Config reference:** `config/tenancy.php` → `migration_parameters.path`

**Run:** `php artisan migrate --path=database/migrations/tenant --database=tenant`

**Lock:** Phase 3A+ — see PROJECT_MANIFEST TENANCY-DUAL-DB.
