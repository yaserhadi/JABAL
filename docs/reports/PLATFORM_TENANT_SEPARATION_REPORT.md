# Platform / Tenant Separation — Implementation Report

**Date:** 2026-05-26 (updated)  
**Branch:** `feature/platform-tenant-separation`  
**ADR:** [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md) (Draft)  
**Lock:** `PLATFORM-TENANT-SEPARATION` (**Active** — 2026-05-26)  
**Initiative:** [JABAL_CORE_REALIGNMENT.md](JABAL_CORE_REALIGNMENT.md)  
**Test gate:** [TEST_STABILIZATION_GATE.md](TEST_STABILIZATION_GATE.md) — **CLOSED** (Core Realignment Stage 1; 93 passed, 0 failed)  
**Stage 2 exit:** **Done** (2026-05-26) — full suite **93 passed, 0 failed** (~1286s); log: `storage/logs/stage-2-test.log`

## Summary

Implemented the foundational split between **Platform Management** and **Tenant Application** per ADR-0007:

- `platform_users` on `jabal_central` with `PlatformUser` model and `platform` auth guard
- Tenant application users on `jabal_tenant_shared` (`users` + `tenant_id`) with `TenantUser` model
- `TENANCY_MODE=shared_db` configuration and `TenantStorageResolver` contract
- Platform routes under `/platform/*` with `EnsureNoTenancy`
- Tenant register/login flows use `TenantRegistrationService`
- API token/header contract: mismatch → **403**, unauthenticated → **401** (`ValidateTenantToken`)
- Legacy Phase 4 branches remain unmerged; MFA/SSO re-port deferred (Stage 6+)

## Delivered

| Area | Status |
|------|--------|
| ADR-0007 | Draft (lock Active) |
| Manifest + INTEGRITY lock | **Active** (2026-05-26) |
| Test stabilization gate | **CLOSED** |
| Stage 3 — `TENANCY_MODE` strategy + resolver contract + ADR appendix A | Done (design/docs) |
| `.env.example` tenancy vars | Done |
| `TenantStorageResolver` | Done |
| Module boundary doc | Done |
| `platform_users` + Platform auth | Done |
| Tenant `users` migration (tenant DB) | Done |
| `TenantUser` / registration / login | Done |
| Legacy `/admin` redirects | Done |
| Isolation tests | `PlatformTenantIsolationTest` |

## Deferred

| Item | Stage |
|------|-------|
| Impersonation token redesign (full audit/TTL) | Follow-up |
| `database_per_tenant` / `schema_per_tenant` runtime wiring | Stage 5 (`TenantDatabaseProvisioner` stub) |
| Legacy Phase 4B MFA/SSO concepts on tenant users | Stage 6+ |
| ADR-0007 **Final** | Owner approval |
| Legacy roadmap Phase 5A–5C productization | Explicit scope only — **not started** (distinct from Core Realignment stages) |

## Legacy Phase 4 branches

Do **not** merge `feature/phase-4b-*` or `feature/mfa-*` until Core Realignment is on `main` and Stage 6+ re-home is complete. See [JABAL_CORE_REALIGNMENT.md](JABAL_CORE_REALIGNMENT.md).

## Stage 2 closure (2026-05-26)

Implementation commits on `feature/platform-tenant-separation`:

| Commit | Summary |
|--------|---------|
| `382fcbb` | Platform users, guard, `/platform` routes |
| `8209bf4` | Tenant application users, tenant DB schema, Sanctum tokens |
| `4c9d677` | Tenant RBAC on tenant connection, web membership middleware |
| `1fba242` | Feature test alignment + `PlatformTenantIsolationTest` |

**Exit criteria met:**

- Platform and tenant guards/routes isolated (`EnsureNoTenancy`, `PlatformTenantIsolationTest`)
- Tenant registration creates `TenantUser` on tenant data layer (`AuthTest::test_register_redirects_to_dashboard_with_tenant_admin_permissions`)
- API contract preserved: token/header mismatch → **403**; missing/invalid auth → **401** (`TenancySecurityTest`)
- Full suite: **93 passed** (204 assertions)

MFA/SSO artifacts (`AccountController`, `Mfa*`, ADR-0006) remain **uncommitted** — Stage 6 scope.

## Verification

```bash
php artisan test --filter=PlatformTenantIsolationTest
php artisan test --filter=TenancySecurityTest
php artisan test --filter=TokenTest
php artisan test --filter=RbacTenancyTest
php artisan test --filter="AuthTest|UserAuthTest"
php artisan test
```
