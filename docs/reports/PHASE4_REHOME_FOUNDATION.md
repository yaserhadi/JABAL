# Phase 4 Re-home — Foundation Report (Wave 1.0)

**Status:** Wave 1 deliverable — **READY FOR PR** (owner sign-off §9.4, 2026-05-28)  
**Branch:** `feature/core-realignment-foundation` @ `f7c9a94`  
**LKGS:** `main` @ `4f40f0b` (106 tests) → **114 tests pass** on foundation branch  
**Date:** 2026-05-28  
**Authority:** ADR-0007, `PLATFORM-TENANT-SEPARATION` lock

This report gates F1–F8 and Wave 2+ product re-home. **Pre-merge requirement:** §9 legacy sunset registry must be accepted before merge to `main`.

---

## 1. Artifact relocation matrix

| Artifact | Current Location | Target Location | Reason | ADR Reference |
|----------|------------------|-----------------|--------|---------------|
| Tenant membership (legacy bridge) | `jabal_central.tenant_users` | Tenant layer `memberships` (auth authority) | R11 — contacts ≠ users; membership not auth on central | ADR-0007 §3.2 |
| Tenant account contacts | N/A | `tenant_contacts` + role catalog + assignments | R11 + R12 commercial/admin only | ADR-0007 §3.2 |
| Tenant commercial ownership | N/A | `tenants.commercial_owner_contact_id`, `tenant_ownerships` | R14 — ownership ≠ contact role change | ADR-0007 §3.2 |
| Platform RBAC | `EnsurePlatformAdmin` + `isPlatformOperator()` boolean | `platform_roles` / `platform_permissions` + pivots | R10 Path A (owner-locked) | ADR-0007 §3.1.5 |
| Seat limits | N/A / implicit | `subscriptions.seat_limit` on central (Wave 2) | Commercial metadata — not user roster | ADR-0004 |
| Tenant MFA / session registry | Legacy branches on central | Tenant layer only (Wave 3) | R8 — no cross-layer auth artifacts | ADR-0007 §3.1.2 |
| Platform sessions | `jabal_central.platform_sessions` | Unchanged (central) | F1 verified on LKGS | ADR-0007 §3.1.2 |
| Tenant sessions | Tenant `sessions` | Unchanged (tenant layer) | F1 verified on LKGS | ADR-0007 §3.1.2 |

---

## 2. F2 — Mandatory artifact owner matrix

| Artifact | Owner | Storage | Status |
|----------|-------|---------|--------|
| `platform_users` | Platform | `jabal_central` | **Implemented** (LKGS) |
| `platform_roles` | Platform | `jabal_central` | **Implemented** (Wave 1 F4) |
| `platform_permissions` | Platform | `jabal_central` | **Implemented** (Wave 1 F4) |
| `platform_sessions` | Platform | `jabal_central` | **Implemented** |
| `platform_password_reset_tokens` | Platform | `jabal_central` | **Implemented** |
| `tenant_contacts` (+ role catalog) | Platform (commercial) | `jabal_central` | **Implemented** (Wave 1) |
| `tenants`, `domains`, subscriptions, plans | Platform | `jabal_central` | tenants/domains **Implemented**; billing **Implemented** (Wave 2 core) |
| Tenant commercial ownership (R14) | Platform | `jabal_central` | **Implemented** (Wave 1) |
| tenant users (`users`) | Tenant | tenant layer | **Implemented** |
| tenant memberships | Tenant | tenant layer `memberships` | **Implemented** — **current authority** |
| central `tenant_users` (legacy pivot) | — | `jabal_central` | **Deprecated** — see §9.1 |
| tenant roles / permissions | Tenant | tenant layer (Spatie) | **Implemented** |
| tenant sessions | Tenant | tenant layer | **Implemented** |
| tenant password_reset_tokens | Tenant | tenant layer | **Implemented** |
| tenant MFA | Tenant | tenant layer | **Planned** → Wave 3 |
| tenant SSO | Tenant | tenant layer | **Planned** → Wave 3 |

**Forbidden:** Spatie for platform RBAC; shared roles/permissions/pivots across layers.

---

## 3. Bidirectional Platform / Tenant responsibility boundary

### Platform Management answers (central / `platform.web` only)

- Who legally/commercially owns this tenant? → `tenants.commercial_owner_contact_id` / `tenant_ownerships` (R14)
- Who should we communicate with? → `tenant_contacts` + contact role assignments (R12)
- What plan is active? → subscriptions / entitlements (Wave 2)
- What is the tenant status? → `tenants.status`
- Can this tenant pay / renew / be billed? → billing + subscriptions (platform only)
- How many seats? → `subscriptions.seat_limit` (metadata, not roster)

### Platform Management never answers

- Can this user log in?
- Who belongs to the tenant application?
- What application role does this user have?
- Can this user access the tenant app?

### Tenant Application answers (tenant layer / `tenant.web` only)

- Who belongs to this tenant? → `memberships` (authority)
- What role does this user have? → tenant Spatie RBAC
- Can this user access the application? → tenant auth + membership + permissions
- Can this user log in? → `users` + sessions + MFA/SSO (Wave 3)

### Tenant Application never answers

- Can this tenant pay? / renew? / be suspended?
- Who is the commercial owner of the contract?
- What plan is this tenant on?

---

## 4. Future Tenancy Modes Validation

| Decision | shared_db | database_per_tenant | schema_per_tenant | Notes |
|----------|-----------|---------------------|-------------------|-------|
| `tenant_contacts` (+ role catalog) | ✅ | ✅ | ✅ | central only — mode-agnostic |
| `tenant_ownerships` / commercial owner (R14) | ✅ | ✅ | ✅ | central only |
| `platform_roles` / `platform_permissions` | ✅ | ✅ | ✅ | central only |
| `memberships` (tenant layer authority) | ✅ | ✅ | ✅ | via `TenantStorageResolver`; no shared_db-only service queries |
| `tenant_contacts` used for login | ❌ | ❌ | ❌ | R11 — forbidden |
| Central `tenant_users` as auth authority | ❌ deprecated | ❌ | ❌ | R11 — Wave 1 cutover |

**Exit rule:** No Wave 1 decision marked ❌ for `database_per_tenant` or `schema_per_tenant` without documented mitigation.

---

## 5. R8–R15 compliance

| Rule | Wave 1 compliance |
|------|---------------------|
| **R8** Artifact ownership | Platform artifacts central only; tenant auth artifacts tenant layer only; similarity ≠ shared tables |
| **R9** Registry ≠ identity | `tenants`/`domains`/`tenant_contacts` on central; login identity on tenant layer |
| **R10** F4 Path A | `platform_roles`/`platform_permissions` implemented; boolean gate retired |
| **R11** Contacts ≠ users | `tenant_contacts` central; `memberships` tenant authority; central `tenant_users` deprecated (§9.1) |
| **R12** Contact role catalog | `tenant_contact_roles` + assignments; no boolean purpose columns |
| **R13** Platform deployability | Platform middleware/services must not import tenant auth stack for `/platform/*` |
| **R14** Ownership ≠ contact roles | `commercial_owner_contact_id` + optional `tenant_ownerships`; contact churn ≠ ownership transfer |
| **R15** Commercial identity ≠ application identity | `tenant_contacts` / contact roles are platform commercial records only; `users` are tenant application identities only; same email allowed, no auth coupling (§5.1) |

### 5.1 R15 — Commercial Identity ≠ Application Identity

**Rule:** A commercial contact record is not an application user, and an application user is not automatically a commercial contact.

| Identity | Storage | May log in? | Platform commercial role? |
|----------|---------|-------------|---------------------------|
| `tenant_contacts` | central | **Never** | Yes (billing, legal, account_owner contact purpose, etc.) |
| `users` (TenantUser) | tenant layer | Yes (tenant app) | **Never** (unless separately modeled as a contact) |

**Examples (allowed and expected):**

- `contact@company.com` — Commercial Owner + Billing Contact on central; **no** tenant application account.
- `user@company.com` — Active tenant member with MFA/sessions; **no** platform commercial contact row required.

**Forbidden:** Using `tenant_contacts` for login, RBAC, MFA, or sessions; assuming `users.email === tenant_contacts.email` implies one identity; creating application users from contact rows without explicit tenant registration flow.

---

## 6. F4 — Platform Identity Governance (lifecycle)

All operations run inside Platform Management runtime only (`platform.web`, `platform` guard, `jabal_central`):

| Operation | Permission (Wave 1 seed) | Storage |
|-----------|--------------------------|---------|
| Access platform admin | `platform.access` | `platform_permissions` |
| Manage platform settings | `platform.settings.manage` | central |
| View platform audit | `platform.audit.view` | central |

**Forbidden:** tenant runtime creating/disabling `platform_users`; tenant guard assigning `platform_roles`.

**Wave 1 minimum:** tables + permission checks + seeder for first operator role.

---

## 7. F1–F8 pass table

| Gate | Status | Evidence |
|------|--------|----------|
| **F1** Runtime profiles | **Pass** (verify) | LKGS: `ConfigureApplicationRuntime`, `RuntimeSessionIsolationTest`, UAT Suite C |
| **F2** Auth artifact ownership | **Pass** (this report) | §2 matrix + ADR-0007 §3.1.2 |
| **F3** Storage / resolver adoption | **Pass** (design) | `TenantStorageResolver` bound; §4 tenancy modes matrix |
| **F4** Platform RBAC Path A | **Pass** | Migrations + `EnsurePlatformAdmin` → `platform.access` |
| **F5** Billing / commercial | **Draft ADR-0004** + Wave 2 core tables | ADR-0004; `Modules/Billing` on central |
| **F6** Module boundaries | **Pass** | 4A → `Modules/Billing`; 4B → `Modules/Identity`; kernel in `app/`; see §7.1 module gate |
| **F7** Forbidden crossover tests | **Pass** | `ForbiddenArtifactCrossoverTest` |
| **F8** No cross-DB auth dependency | **Pass** | `CrossDatabaseAuthDependencyTest`, `PlatformTenantIsolationTest` |

### 7.1 F6 — Module gate verification (Wave 1 + Wave 2)

**Operational note (nWidart modules):** After adding a new module with its own `Modules/<Name>/composer.json`, autoload is **not** refreshed automatically. Before test execution or provider discovery:

```bash
composer dump-autoload -o
```

Failure mode observed on Wave 2: `Modules\Billing\Providers\BillingServiceProvider` not found until autoload was regenerated — **autoload / provider discovery**, not an architectural defect in Billing.

| Check | `Modules/Billing` | Gate |
|-------|-------------------|------|
| `modules_statuses.json` entry | `"Billing": true` | Pass |
| Module `composer.json` PSR-4 autoload | `Modules\Billing\` → `app/` | Pass |
| `composer dump-autoload -o` | Successful | Pass |
| Provider boots (`php artisan test`) | 114 passed | Pass |

**Billing loadability gate: passed** on `feature/core-realignment-foundation`.

Apply the same gate to any future module (`Modules/Observability`, etc.) after MODULE-CREATION-GATE approval.

---

## 8. R13 — Platform runtime deployability audit

| Component | Tenant auth import? | Verdict |
|-----------|---------------------|---------|
| `routes/platform.php` | No | OK |
| `Platform\AuthController` | No | OK |
| `EnsurePlatformAdmin` | No (uses `PlatformUser` + platform RBAC) | OK |
| `EnsureNoTenancy` | No | OK |
| Platform settings/audit controllers | No tenant login | OK |

Platform login must not query tenant `users`, `memberships`, or tenant Spatie tables.

---

## 9. Legacy sunset registry (pre-merge gate)

The architecture is correct when **new code** uses the target model only. These legacy artifacts remain in the schema from pre–ADR-0007 work and **must not be reused** for new features.

### 9.1 Central `tenant_users` (membership bridge)

```text
Current authority (auth / membership checks):
    memberships (tenant layer)

Legacy table:
    jabal_central.tenant_users

Legacy model:
    Modules\Tenancy\Models\TenantUser (@deprecated)

Status:
    Deprecated
    Read-only (no new writes in Wave 1 runtime paths)
    Scheduled for removal

Wave 1 runtime:
    EnsureUserBelongsToTenant, ValidateTenantToken, TenantMemberController,
    TenantRegistrationService, UserService, TenantRbacProvisioner → memberships

Remaining references (legacy only):
    Tenant::tenantUsers() relationship, TenantUserFactory, historical migration,
    MANIFEST/TENANCY-DUAL-DB doc lines (to be updated on drop)

Removal plan (post-merge, owner-approved):
    1. Data migration: copy any orphan central tenant_users rows → tenant memberships (if any remain)
    2. Drop central tenant_users table + deprecated model/factory
    3. Update TENANCY-DUAL-DB security lock text (membership checks → tenant layer)
    4. Target: Stage 4 cleanup wave or first release after foundation merge
```

**Agent rule:** Do not add new queries, writes, or auth checks against central `tenant_users`. If you find one in review, treat it as a **merge blocker**.

### 9.2 Legacy central Spatie RBAC (Phase 3 pre-separation)

```text
Artifact:
    jabal_central.roles, permissions, model_has_roles, … (Spatie)

Status:
    Legacy from Phase 3B before tenant-layer RBAC cutover
    Not used for new platform or tenant authorization
    Tenant member RBAC authority: tenant layer Spatie tables only

Removal plan:
    1. Verify zero runtime reads/writes on central Spatie in production paths
    2. Migration to drop central permission tables after audit
    3. ForbiddenArtifactCrossoverTest documents tenant-primary RBAC; central legacy noted

Target: Same cleanup wave as §9.1 or immediately after foundation merge audit
```

### 9.3 Pre-merge checklist (owner)

- [x] §9.1 and §9.2 documented as **mandatory sunset** (not optional cleanup)
- [x] **Owner sign-off (2026-05-28):** §9.1 and §9.2 **approved as mandatory sunset registry** — **not approved as permanent architecture**. `tenant_users` and legacy central Spatie remain **temporary only**; new use = merge blocker; **cleanup wave required** after first merge/PR.
- [x] No new code paths write to `tenant_users` or central Spatie (Wave 1 runtime verified)
- [x] R15 documented: commercial contacts ≠ application users
- [x] 114 tests pass after `composer dump-autoload -o` (verified on branch before PR)
- [x] Fresh DB bootstrap verified: `migrate:fresh` central + tenant + `db:seed` (2026-05-28)
- [x] Production readiness review — **passed** (2026-05-28)

### 9.4 Owner sign-off record (final)

**Decision:** Core Realignment Foundation — **READY FOR PR** (Pull Request workflow; no direct push to `main`).

| Artifact | Status | Lifecycle | Authority | New usage |
|----------|--------|-----------|-----------|-----------|
| **§9.1** `jabal_central.tenant_users` | Approved as **Mandatory Sunset Artifact** | Deprecated → Read-only → Scheduled removal | **NONE** (tenant `memberships` is authority) | **BLOCKER** |
| **§9.2** Legacy central Spatie RBAC | Approved as **Mandatory Sunset Artifact** | Deprecated → Read-only → Scheduled removal | **NONE** (tenant-layer Spatie is authority) | **BLOCKER** |

**Architectural outcomes confirmed:**

- **Platform Management ≠ Tenant Application** — documented and implemented (not ADR-only).
- **Commercial Identity ≠ Application Identity (R15)** — governing project rule.

**Post-merge next wave:** Cleanup — remove central `tenant_users`; remove legacy central Spatie RBAC (§9.1–§9.2 removal plan).

---

## References

- [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md)
- [JABAL_CORE_REALIGNMENT.md](JABAL_CORE_REALIGNMENT.md)
- Phase 4 re-home plan: `.cursor/plans/phase_4_re-home_revised_9d6e261c.plan.md`
