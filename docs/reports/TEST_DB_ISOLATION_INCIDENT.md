# Test DB Isolation Incident — central DB wipe

**Date:** 2026-05-27
**Branch:** `feature/platform-tenant-separation`
**Severity:** High (silent dev data loss on every `php artisan test` run)
**Status:** **RESOLVED — locked in by two independent safeguards**

---

## 1. Symptom

Login was returning *"The provided credentials do not match our records"* for **every** user — including the seeded `admin@jabal.test` and `user@jabal.test`, and including users registered moments earlier — even though the rows were visibly present in `jabal_tenant_shared.users`.

The user noticed this only happened on PostgreSQL; the project never lost data on MariaDB before.

## 2. Runtime evidence

Instrumented `Modules/Identity/app/Http/Controllers/AuthController::login` with logs at four checkpoints (H1 user lookup, H2 tenant resolution, H3 pre-attempt state, H4 attempt result).

The reproduction produced (excerpt from `debug-3d6a79.log`):

```jsonc
{"hypothesisId":"H1","userFound":true,"userTenantId":"a1e0071e-4bcb-4fc1-bd3c-a9036b4e4df8", ...}
{"hypothesisId":"H2","tenantIdQueried":"a1e0071e-4bcb-...","tenantFound":false,"tenantStatus":null}
```

Direct DB inspection confirmed:

```text
central.tenants       count = 0
central.tenant_users  count = 0     (memberships)
tenant.users          count = 259   (orphaned)
```

Every user row pointed to a non-existent tenant. The code was correct; the data was inconsistent.

## 3. Root cause

`phpunit.xml` did **not** override `DB_DATABASE_CENTRAL` / `DB_DATABASE_TENANT`. Combined with `tests/TestCase.php` using `Illuminate\Foundation\Testing\RefreshDatabase`, every `php artisan test` invocation:

1. Inherited the dev `.env` values: `central=jabal_central`, `tenant=jabal_tenant_shared`.
2. Ran `migrate:fresh` on the central connection on the first test → **dropped every table in `jabal_central`**.
3. Ran `migrate --database=tenant` from `TestCase::afterRefreshingDatabase()` → **dropped every table in `jabal_tenant_shared`**.
4. Re-ran migrations on both.
5. Each test then opened a transaction, did its work, rolled back. But the wipe at step 2/3 had already happened.

The reason MariaDB never produced this on past projects is that those projects had `<env name="DB_DATABASE" value="..._testing"/>` overrides in `phpunit.xml`. The PG project here was missing them, so PHPUnit silently inherited the dev DB names from `.env`. This is **not** a PostgreSQL quirk — it's a missing config that PG happened to expose.

## 4. Fix (two independent layers)

### 4.1 Config-level isolation — `phpunit.xml`

```9:14:phpunit.xml
        <env name="CACHE_STORE" value="array"/>
        <!--
            Tests MUST NOT touch the dev databases. RefreshDatabase runs migrate:fresh
            on the central connection; without these overrides PHPUnit inherits
            DB_DATABASE_* from .env and wipes the live dev DBs. See tests/TestCase.php
            guard in setUp() for defense-in-depth.
        -->
        <env name="DB_DATABASE_CENTRAL" value="jabal_central_testing"/>
        <env name="DB_DATABASE_TENANT" value="jabal_tenant_shared_testing"/>
```

Two new PostgreSQL databases were created (idempotent, empty): `jabal_central_testing`, `jabal_tenant_shared_testing`. Each PHPUnit run now only ever migrates and wipes those.

### 4.2 Defense-in-depth guard — `tests/TestCase.php::setUp()`

```22:42:tests/TestCase.php
    protected function setUp(): void
    {
        $central = (string) env('DB_DATABASE_CENTRAL', '');
        $tenant = (string) env('DB_DATABASE_TENANT', '');

        if (! str_ends_with($central, '_testing') || ! str_ends_with($tenant, '_testing')) {
            throw new \RuntimeException(
                'Refusing to run tests against non-isolated databases. '.
                'Expected DB names ending in "_testing", got central='.$central.', tenant='.$tenant.'. '.
                'Set DB_DATABASE_CENTRAL and DB_DATABASE_TENANT in phpunit.xml.'
            );
        }

        parent::setUp();
    }
```

The guard reads env BEFORE the framework boots and BEFORE `RefreshDatabase` can call `migrate:fresh`. If anyone (or a future merge) removes or alters the `phpunit.xml` overrides so the test DB names no longer end in `_testing`, every test fails fast with a clear `RuntimeException` and zero DB operations.

## 5. Verification (was performed, can be re-run)

### 5.1 Functional verification

Full suite ran clean against isolated DBs:

```text
Tests:    29 passed (62 assertions)
Duration: 38.82s   (was ~161s before — smaller test DBs run faster)
```

### 5.2 Isolation verification (dev DB survived test run)

After `php artisan test --filter=AuthTest|UserAuthTest|TenancySecurityTest|PlatformTenantIsolationTest`:

```text
dev central.platform_users = 1   (superadmin still there)
dev central.tenants        = 1   (yaser@yh.com's tenant still there)
dev tenant.users           = 1   (yaser@yh.com still there)
```

Login on the dev environment with `yaser@yh.com` succeeded immediately after the test run — H1–H4 instrumentation logs in `debug-3d6a79.log` confirmed `attemptResult: true`.

### 5.3 Guard verification (negative test)

Simulated misconfiguration by forcing dev DB names through env:

```text
RuntimeException: Refusing to run tests against non-isolated databases.
Expected DB names ending in "_testing", got central=jabal_central, tenant=jabal_tenant_shared.
Set DB_DATABASE_CENTRAL and DB_DATABASE_TENANT in phpunit.xml.

at C:\xampp\htdocs\Jabal\tests\TestCase.php:37
Time: 00:00.078, Memory: 18.00 MB
```

Throwable raised in 78 ms with zero DB operations — guard works as designed.

## 6. One-time setup (already done on this workstation)

To replicate the test DBs on a new workstation:

```bash
psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE "jabal_central_testing"'
psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE "jabal_tenant_shared_testing"'
```

No other action needed — `RefreshDatabase` builds the schema on first test run.

## 7. Future-proofing checklist

| Item | Status |
|---|---|
| `phpunit.xml` overrides committed | Yes (this commit) |
| `tests/TestCase.php` guard committed | Yes (this commit) |
| Incident report (this file) committed | Yes (this commit) |
| `tests/README.md` warning added | Yes (this commit) |
| Verification commands documented | Yes — see §5 |

## 8. References

- Original symptom thread: registration succeeded but subsequent login returned "credentials do not match"
- Related earlier fix in this branch: `BelongsToTenant` infinite-recursion OOM (separate incident — see `feature/platform-tenant-separation` history)
- Laravel docs on test DB isolation: https://laravel.com/docs/testing#main-content
