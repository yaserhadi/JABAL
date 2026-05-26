# Platform / Tenant Separation — Implementation Report

**Date:** 2026-05-26 (updated)  
**Branch:** `feature/platform-tenant-separation`  
**ADR:** [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md) (Draft)  
**Lock:** `PLATFORM-TENANT-SEPARATION` (**Active** — 2026-05-26)  
**Test gate:** [TEST_STABILIZATION_GATE.md](TEST_STABILIZATION_GATE.md) — **CLOSED** (93 passed, 0 failed)

## Summary

Implemented the foundational split between **Platform Management** and **Tenant Application** per ADR-0007:

- `platform_users` on `jabal_central` with `PlatformUser` model and `platform` auth guard
- Tenant application users on `jabal_tenant_shared` (`users` + `tenant_id`) with `TenantUser` model
- `TENANCY_MODE=shared_db` configuration and `TenantStorageResolver` contract
- Platform routes under `/platform/*` with `EnsureNoTenancy`
- Tenant register/login flows use `TenantRegistrationService`
- API token/header contract: mismatch → **403**, unauthenticated → **401** (`ValidateTenantToken`)
- Phase 4 branches remain unmerged; MFA/SSO re-port deferred (Phase 7)

## Delivered

| Area | Status |
|------|--------|
| ADR-0007 | Draft (lock Active) |
| Manifest + INTEGRITY lock | **Active** (2026-05-26) |
| Test stabilization gate | **CLOSED** |
| Phase 3 — `TENANCY_MODE` strategy + resolver contract + ADR appendix A | Done (design/docs) |
| `.env.example` tenancy vars | Done |
| `TenantStorageResolver` | Done |
| Module boundary doc | Done |
| `platform_users` + Platform auth | Done |
| Tenant `users` migration (tenant DB) | Done |
| `TenantUser` / registration / login | Done |
| Legacy `/admin` redirects | Done |
| Isolation tests | `PlatformTenantIsolationTest` |

## Deferred

| Item | Phase |
|------|-------|
| Impersonation token redesign (full audit/TTL) | Follow-up |
| `database_per_tenant` / `schema_per_tenant` runtime wiring | Phase 5+ (`TenantDatabaseProvisioner` stub) |
| Phase 4B MFA/SSO on tenant users | Phase 7 |
| ADR-0007 **Final** | Owner approval |
| Phase 5A–5C productization / advanced isolation | Explicit scope only — **not started** |

## Phase 4 branches

Do **not** merge `feature/phase-4b-*` or `feature/mfa-*` until separation work is on `main` and Phase 7 re-home is complete.

## Verification

```bash
php artisan test --filter=PlatformTenantIsolationTest
php artisan test --filter=TenancySecurityTest
php artisan test
```
