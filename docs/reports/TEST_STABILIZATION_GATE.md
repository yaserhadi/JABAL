# Test Stabilization Gate — feature/platform-tenant-separation

**Date:** 2026-05-26  
**Branch:** `feature/platform-tenant-separation`  
**Plan:** `.cursor/plans/test_stabilization_gate_4fd7b7c0.plan.md`

---

## API auth contract (decided)

| Condition | HTTP status |
|-----------|-------------|
| Missing or invalid bearer token / unauthenticated | **401** |
| Missing `X-Tenant-Id` header | **401** |
| Valid token, `X-Tenant-Id` ≠ token `tenant:{uuid}` ability | **403** (`Header does not match token ability`) |
| Valid token, user not active member of header tenant | **403** |
| Tenant missing or inactive | **403** |
| Token without `tenant:{uuid}` ability | **403** |

**Rationale:** 401 = unauthenticated; 403 = authenticated but forbidden for this tenant. A valid Sanctum token with a mismatched tenant header is forbidden tenant access, not unauthenticated. The contract must be strict — tests assert exactly 403 for mismatch, not `401|403`.

**Implementation:** [`app/Http/Middleware/ValidateTenantToken.php`](../../app/Http/Middleware/ValidateTenantToken.php)

- `rejectTokenHeaderMismatchForBearer()` resolves bearer token abilities **before** Sanctum auth runs, returns **403** on mismatch (no `auth:sanctum` 401 can interfere).
- `validateAuthenticatedAccess()` enforces active tenant, ability match, tenancy context, and membership after auth.
- Kernel middleware priority in `AppServiceProvider`: `ValidateTenantToken` → `Authenticate` → Stancl tenancy init.

**Tests asserting 403 on mismatch (strict; no 401\|403 alternates):**

- `Tests\Feature\TenantSettingsTest::test_api_settings_requires_matching_tenant_header`
- `Tests\Feature\TenancySecurityTest::test_api_token_ability_mismatch_with_header_returns_403`

---

## 1. Gate result

**Gate status: CLOSED**

Full suite run on 2026-05-26, branch `feature/platform-tenant-separation`:

```
Tests:    93 passed (204 assertions)
Duration: 1727.01s
exit_code: 0
```

Command used:

```bash
git branch --show-current   # feature/platform-tenant-separation
php artisan test
```

---

## 2. Failure classification

### Bucket definitions

- **Bucket 1 — Expected ADR-0007 drift:** behavior changes by design per ADR-0007; tests updated or replaced in a follow-on story.
- **Bucket 2 — Regressions / setup issues:** introduced by the separation branch, corrected without changing agreed architecture.
- **Bucket 3 — Stale tests:** relied on central `users` or legacy flows; updated to tenant registration helpers where feasible.

### 2.1 Bucket 2 — Regressions fixed

- **API middleware order**
  - **Cause:** `InitializeTenancyByRequestData` ran before `ValidateTenantToken`, scoping queries and breaking auth in some paths.
  - **Fix:** Route group `ValidateTenantToken` → `auth:sanctum` → `InitializeTenancyByRequestData` in `Modules/Api/routes/api.php`, plus kernel priority in `AppServiceProvider` so execution order is **ValidateTenantToken → Sanctum → Stancl tenancy init** (403 on header/ability mismatch before auth; auth before scoped user reload).

- **401 vs 403 on token/header mismatch**
  - **Cause:** Mismatch returned 401 because Stancl/Sanctum ran before ability check when middleware priority was not set.
  - **Fix:** `ValidateTenantToken::rejectTokenHeaderMismatchForBearer()` runs first via kernel priority; mismatch → **403**. Token revoke test updated to include `tenant:{uuid}` ability on token.

- **Web `actingAs` before tenancy init**
  - **Fix:** `actingAsTenantUser()` in `tests/TestCase.php`; web tests updated.

- **Shared email collisions (Auth/UserAuth/Token)**
  - **Fix:** Unique emails via `uniqid()` and `registerTenantUser()`.

- **TenantMemberManagement setup**
  - **Fix:** Aligned member `User` + `TenantUser` rows; `actingAsTenantUser` for member routes.

- **Sanctum on tenant DB**
  - **Fix:** `TenantPersonalAccessToken` on tenant connection; per-row password verify on login.

### 2.2 Bucket 1 — Monitor (not a stabilization regression)

- Web routes may **403** when `tenant_users` membership exists but no `users` row for that `tenant_id` on shared_db (`EnsureUserBelongsToTenant`). Documented for ADR-0007 follow-up.

---

## 3. Bucket summary

| Bucket | Status |
|--------|--------|
| 1 — Expected drift | Web shared_db membership edge documented |
| 2 — Regressions | **All fixed** |
| 3 — Stale tests | Updated where feasible |

---

## 4. Gate closure

| Criterion | Status |
|-----------|--------|
| Bucket 2 regressions fixed | Yes |
| API contract documented (mismatch → 403) | Yes |
| `ValidateTenantToken` aligned with contract | Yes |
| `php artisan test` 0 failures | **Yes — 93 passed, 0 failed (2026-05-26)** |

**Gate: CLOSED.**

**Lock:** `PLATFORM-TENANT-SEPARATION` is **Active** in `.cursor/memory/PROJECT_MANIFEST.md` (activated 2026-05-26 after this gate closed).

**Initiative:** [JABAL_CORE_REALIGNMENT.md](JABAL_CORE_REALIGNMENT.md) — this gate closes **Stage 1** (stabilization).

**Next:** Stages 2–3 in progress on branch; Stage 5+ implementation requires explicit scope — do not start until owner approves.
