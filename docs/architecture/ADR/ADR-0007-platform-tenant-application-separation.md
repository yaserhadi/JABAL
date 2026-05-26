# ADR-0007: Platform Management and Tenant Application Separation

Status: **Draft**  
Date: 2026-05-24  
Owner: KSS Steward - YH

**Initiative:** **JABAL Core Realignment** (not a continuation of legacy roadmap Phase 4)  
**Execution plan:** `.cursor/plans/platform_tenant_identity_separation_75f9f41b.plan.md`  
**Stage map:** [JABAL_CORE_REALIGNMENT.md](../../reports/JABAL_CORE_REALIGNMENT.md)  
**Governance lock (Active):** `PLATFORM-TENANT-SEPARATION` in `.cursor/memory/PROJECT_MANIFEST.md` (activated 2026-05-26 after test stabilization gate closed)

---

## Supersession notice

**Legacy roadmap Phase 3** (domain + RBAC on `main`) remains valid operationally; it is **not** the label for this initiative.

**Unmerged legacy Phase 4 branches** (`feature/phase-4a-*`, `feature/phase-4b-*`, `feature/mfa-*`, etc.) are **experimental architecture iterations**. They are **superseded** by **JABAL Core Realignment**. Concepts (billing, entitlements, MFA, OIDC) may be reused selectively after re-home on the corrected platform/tenant model (Stage 6+); **architectural assumptions** from those branches (central `users` as tenant login, mixed platform/tenant auth, old RBAC placement, pre-separation billing coupling) are **not authoritative**.

Agents must not treat current work as “Phase 4 with tweaks.”

---

## 1. Context

JABAL is a reusable multi-tenant SaaS core. The codebase must behave as **two separated applications** inside one Laravel product:

1. **Platform Management** — operators, tenant registry, commercial control, provisioning, platform audit.
2. **Tenant Application** — tenant end users, workspaces, tenant security, and business modules.

Today (pre-ADR), a single central `users` table and `tenant_users` membership on `jabal_central` blur platform operators with tenant customers. Unmerged **legacy Phase 4** branches (4A billing, 4B SSO/MFA) built on that model. **Those branches must not merge to `main` until Core Realignment stages complete and capabilities are re-introduced on the new architecture (Stage 6+).**

Evalty and similar products are **reference models only**. JABAL must **not** copy Evalty literally. Use explicit **`PlatformUser`** and **`TenantUser`** models (separate classes and guards) to reduce accidental context leakage. A single `User` class used everywhere with only connection switching is discouraged for this codebase.

ADR-0001 selected stancl/tenancy and preserved `tenants.isolation_level` (`shared` | `schema` | `database`). This ADR defines identity separation and how tenant data is accessed so domain code does not assume one isolation strategy forever.

---

## 2. Options Considered

### Option A: Central identity registry only (status quo)

**Description:** All humans in `jabal_central.users`; `tenant_users` pivot; Spatie RBAC on central with `tenant_id` team.

**Rejected for target architecture.** Platform and tenant users remain coupled; shared tenant DB isolation relies heavily on `tenant_id` discipline and central RBAC.

### Option B: Platform central + tenant users in tenant data layer (shared_db first) ✓ Selected

**Description:**

- **Platform:** `platform_users` on `jabal_central` only; platform guards, routes, permissions, UI.
- **Tenant:** `TenantUser` (and tenant RBAC) on tenant data layer; initial mode `TENANCY_MODE=shared_db` in `jabal_tenant_shared` with `tenant_id` on rows.
- **Access:** `TenantStorageResolver` + tenant context + `BelongsToTenant` (or equivalent contracts)—not ad-hoc `Model::where('tenant_id', $id)` in domain services.
- **Future:** Same abstractions support `database_per_tenant` and `schema_per_tenant` via stancl without rewriting domain modules.

**Selected.**

### Option C: Full Evalty clone (tenant DB users only; central register creates tenant row only)

**Description:** No central user for workspace owners; bootstrap via provisioning job into tenant DB.

**Deferred.** May inform organization-tenant onboarding later; personal-tenant registration on JABAL may still create registry + tenant user in one flow under Option B.

---

## 3. Decision

### 3.1 Two applications (one Laravel codebase)

| Application | Entry | Auth | Data |
|-------------|-------|------|------|
| **Platform Management** | `/platform` or `/admin` (prefix TBD in implementation) | Guard `platform` → `platform_users` | `jabal_central` platform tables only |
| **Tenant Application** | `/t/{tenant}/...` (path; domain later optional) | Guard `tenant` / `web` → tenant connection `users` | Tenant data layer via resolver |

**Cross-application rules:**

- Platform user **must not** access tenant application except via **documented, audited, time-limited impersonation** (stancl impersonation pattern + platform audit).
- Tenant user **must not** access platform management routes or APIs.

### 3.2 Central database (`jabal_central`) — platform scope

**Owns (non-exhaustive):**

- `platform_users`
- `tenants`, `domains`
- `plans`, `subscriptions`, invoices/billing artifacts (per ADR-0004)
- `tenant_provisioning_status`, `tenant_database_config` (isolation metadata)
- Platform audit / KPI configuration

**Does not own:** tenant application login identities, tenant Spatie role assignments (moved to tenant data layer under this ADR).

**Deprecate (phased):** central `users` for new installs as tenant login; `tenant_users` pivot after migration.

### 3.3 Tenant data layer — configurable mode

**Environment (deployment default):**

```env
TENANCY_MODE=shared_db
TENANCY_IDENTIFICATION=path
TENANCY_SHARED_DB_CONNECTION=tenant
TENANCY_DB_CREATION_MODE=manual
```

**Per-tenant override:** `tenants.isolation_level` + `tenant_database_config` (implementation phase).

| `TENANCY_MODE` | `isolation_level` | Tenant storage |
|----------------|-------------------|----------------|
| `shared_db` | `shared` | `jabal_tenant_shared`; rows include `tenant_id`; scope via context + `BelongsToTenant` |
| `database_per_tenant` | `database` | Dedicated DB per tenant; stancl `DatabaseTenancyBootstrapper` when enabled |
| `schema_per_tenant` | `schema` | PostgreSQL schema per tenant; stancl schema bootstrapper when enabled |

**Initial implementation scope:** `shared_db` only. Other modes are architectural targets; resolver interface must exist in design before mode-specific bootstrappers ship.

### 3.4 Abstraction-first tenant access (mandatory)

Domain and module services **must not** hardcode isolation as:

```php
Model::where('tenant_id', $tenantId); // anti-pattern in services
```

**Required patterns:**

1. **Tenant context** — `tenancy()->tenant` after middleware initialization.
2. **`TenantStorageResolver`** — connection name, whether global `tenant_id` scope applies, migration target for tenant migrations.
3. **Tenant-scoped models / repositories** — implement scoping inside the persistence layer, not in every controller.

When mode upgrades to `database_per_tenant`, services keep the same entrypoints; resolver switches connection; `BelongsToTenant` behavior adapts per mode (documented in implementation phase).

### 3.5 Explicit models (Evalty reference only)

| Model | Connection | Used by |
|-------|------------|---------|
| `PlatformUser` | `central` | Platform Management only |
| `TenantUser` | tenant resolver | Tenant Application only |

Do not authenticate platform routes against `TenantUser` or tenant routes against `PlatformUser`.

### 3.6 Module boundaries (target)

**PlatformManagement (conceptual):** PlatformUsers, Tenants registry, Plans, Subscriptions, Billing, Provisioning, Monitoring.

**TenantApplication (conceptual):** Identity (tenant auth, SSO, MFA), TenantUsers, TenantRoles, TenantSettings, Workspaces, business modules.

**Rules:**

- No tenant business logic in platform modules.
- No platform administration in tenant modules.

Incremental module moves from current `Modules/*` layout; no big-bang rename required in Draft ADR.

### 3.7 Legacy Phase 4 branches (reference only — superseded)

Do **not** merge `feature/phase-4b-enterprise-access`, `feature/mfa-hardening`, or related branches until **JABAL Core Realignment** completes and SaaS capabilities are re-introduced (Stage 6+) on the corrected model:

| Reusable (concepts) | Redesign required |
|---------------------|-------------------|
| Plans, subscriptions, entitlements, billing | Central `users`, `tenant_users`, SSO/MFA on central user |
| Audit concepts | Session model split platform vs tenant |
| MFA / step-up ideas (later) | Platform admin vs tenant admin UX |

See [PHASE4B_CLOSURE_REPORT](../../reports/PHASE4B_CLOSURE_REPORT.md) — status **blocked** on this ADR.

### 3.8 Amendments to prior ADRs (when lock Active)

- **ADR-0001:** Identity lives in tenant data layer for tenant app; central `users` no longer authoritative for tenant login. `DatabaseTenancyBootstrapper` enabled when `TENANCY_MODE=database_per_tenant`.
- **ADR-0002 / PHASE3B-RBAC:** Tenant RBAC tables move from “central only” to **tenant data layer** for tenant members; platform permissions remain on central (separate guard/catalog).
- **ADR-0004:** Billing stays on central; unchanged commercial ownership.

---

## 4. Consequences

### Positive

- Clear operator vs customer separation; aligns with product vision (platform ≠ tenant app).
- `shared_db` first reduces operational cost; path to stronger isolation without domain rewrite.
- Legacy Phase 4 concepts can be re-introduced structurally (Stage 6+) instead of blocking `main` with wrong identity model.

### Tradeoffs

- Large migration from central `users` + `tenant_users` to tenant-layer users.
- Dual guard/session/cookie configuration complexity.
- PHASE3B-RBAC lock text in MANIFEST must be updated when this lock goes **Active**.

### Out of scope (Draft ADR)

- Migrations and feature implementation (Core Realignment Stage 5+).
- Hostname/subdomain identification (optional later).
- Full `schema_per_tenant` implementation.

---

## 5. Risks & Controls

| Risk | Control |
|------|---------|
| Accidental platform user in tenant DB | Separate models, guards, seed rules |
| Cross-tenant leak in shared_db | `BelongsToTenant`, isolation tests, no raw `tenant_id` in services |
| Legacy Phase 4 merge before boundary | Branch policy; closure report blocked |
| Evalty copy-paste | Explicit PlatformUser/TenantUser; ADR forbids literal copy |
| Lock/amendment drift | Enforcement sync when Status → Final |

---

## 6. Enforcement Impact (For KSS Sync Only)

When **Status = Final**, mirror into:

- `PROJECT_MANIFEST.md` → Lock `PLATFORM-TENANT-SEPARATION` (**Active**)
- `INTEGRITY_RULES.md` → Verification section `PLATFORM-TENANT-SEPARATION`
- Amend `TENANCY-DUAL-DB` / `PHASE3B-RBAC` bullets per §3.8

**Lock Active (2026-05-26):** `PLATFORM-TENANT-SEPARATION` in MANIFEST after [TEST_STABILIZATION_GATE](../../reports/TEST_STABILIZATION_GATE.md) closed (93 tests). **Core Realignment Stage 3** (tenancy abstraction design/config) delivered in Appendix A. **Stage 5+** runtime for non-`shared_db` requires explicit scope.

---

## Appendix A — `TenantStorageResolver` contract (Core Realignment — Stage 3)

Implementation: [`app/Support/Contracts/Tenancy/TenantStorageResolver.php`](../../app/Support/Contracts/Tenancy/TenantStorageResolver.php)  
Default binding: [`app/Support/Tenancy/DefaultTenantStorageResolver.php`](../../app/Support/Tenancy/DefaultTenantStorageResolver.php)  
Configuration: [`config/tenancy_storage.php`](../../config/tenancy_storage.php) · env: [`.env.example`](../../../.env.example)

| Method | Purpose |
|--------|---------|
| `mode()` | Deployment default from `TENANCY_MODE` |
| `connectionFor(Tenant)` | Laravel DB connection name for tenant-owned models |
| `usesExplicitTenantIdColumn(Tenant)` | Whether `BelongsToTenant` / `tenant_id` applies |
| `effectiveIsolationLevel(Tenant)` | Resolved `shared` \| `database` \| `schema` |

### TENANCY_MODE strategy

| `TENANCY_MODE` (env) | Default behavior | Per-tenant `isolation_level` |
|----------------------|------------------|------------------------------|
| `shared_db` | All tenants use `jabal_tenant_shared`; rows include `tenant_id`; scope via context + `BelongsToTenant` | Honored only when mode allows stronger isolation (see resolver) |
| `database_per_tenant` | Deployment allows dedicated DBs | `database` → `connectionFor()` uses `tenants.tenancy_db_name` when configured |
| `schema_per_tenant` | Deployment allows PG schemas | `schema` → shared connection with schema bootstrapper (Stage 5+) |

**Resolution rules** (`DefaultTenantStorageResolver::effectiveIsolationLevel`):

1. `shared_db` mode → effective level **`shared`** for all tenants (initial implementation scope).
2. `database_per_tenant` mode → `database` when `isolation_level=database` and `TENANCY_ALLOW_DATABASE_PER_TENANT=true`; else fall back to `shared`.
3. `schema_per_tenant` mode → `schema` when `isolation_level=schema` and `TENANCY_ALLOW_SCHEMA_PER_TENANT=true`; else fall back per resolver.

Domain services **must not** hardcode `Model::where('tenant_id', $id)`; use tenant context + scoped models/repositories.

### API auth contract (tenant-scoped routes)

| Condition | HTTP |
|-----------|------|
| Missing/invalid token | **401** |
| Valid token, `X-Tenant-Id` ≠ token `tenant:{uuid}` ability | **403** |
| Valid token, not a member / inactive tenant | **403** |

Enforced by [`ValidateTenantToken`](../../app/Http/Middleware/ValidateTenantToken.php). Evidence: [TEST_STABILIZATION_GATE](../../reports/TEST_STABILIZATION_GATE.md).

### Stage boundary (JABAL Core Realignment)

| Stage | Scope |
|-------|--------|
| **Stage 3** (this appendix) | `.env.example`, `tenancy_storage` config, resolver contract + default implementation, module boundary doc |
| **Stage 5** | `TenantDatabaseProvisioner`, Stancl `DatabaseTenancyBootstrapper` for per-tenant DB/schema, migration mobility |
| **Stage 6+** | Re-introduce legacy Phase 4A/4B **concepts** (billing, SSO, MFA) on corrected platform/tenant architecture — not branch merge as-is |

See [JABAL_CORE_REALIGNMENT.md](../../reports/JABAL_CORE_REALIGNMENT.md) for the full stage map vs legacy roadmap phases.

Module boundary map: [MODULE-BOUNDARY-PLATFORM-TENANT.md](MODULE-BOUNDARY-PLATFORM-TENANT.md).

---

## 7. References

- [ADR-0001](ADR-0001-tenancy.md) — stancl, `isolation_level`
- [ADR-0003](ADR-0003-modular-monolith-module-boundaries.md) — module boundaries
- [ADR-0004](ADR-0004-billing-plans-entitlements.md) — central billing
- [ADR-0005](ADR-0005-enterprise-access-oidc-mfa.md), [ADR-0006](ADR-0006-mfa-architecture-security-model.md) — re-home after separation
- [Tenancy for Laravel](https://tenancyforlaravel.com/) — database/schema modes, impersonation, resource syncing
- [JABAL_CORE_REALIGNMENT.md](../../reports/JABAL_CORE_REALIGNMENT.md) — stage map and supersession
- Plan: `.cursor/plans/platform_tenant_identity_separation_75f9f41b.plan.md`
