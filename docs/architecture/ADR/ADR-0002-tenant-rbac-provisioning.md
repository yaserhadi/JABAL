# ADR-0002: Tenant RBAC provisioning (catalog + runtime)

Status: **Final**  
Date: 2026-03-29  
Owner: YH

---

## 1. Context

Tenant-scoped RBAC uses Spatie with `tenant_id` as the team key (`PHASE3B-RBAC` lock). **Tenant** RBAC tables live on the **tenant data layer** only. **Platform** RBAC is separate on central — see [ADR-0007 §3.1.5](ADR-0007-platform-tenant-application-separation.md). The **catalog** (permission names and `tenant-admin` / `member` matrices) was seeded only for tenants that existed when **`RbacCatalogSeeder`** ran.

**New users** who register receive a **personal tenant** and an **owner** row in `tenant_users`, but until runtime provisioning existed they received **no** Spatie roles. Protected routes (e.g. `permission:dashboard.view`) then returned **403** immediately after signup.

Additionally, **Vue/Vuetify** `v-list-item` `:to` is intended for **Vue Router**; this application uses **Inertia** without `vue-router`, so shell navigation must use **`Link`** or **`router.visit()`**, and ESM **`route()`** from `ziggy-js` requires **`globalThis.Ziggy`** (see `.cursor/memory/LESSONS.md`).

---

## 2. Options Considered

### Option A: Seed-only RBAC (status quo)

**Description:** Rely on periodic re-seeding or manual role assignment for new tenants.  
**Cons:** Broken first-login UX; operational burden; error-prone.  
**Rejected.**

### Option B: Event listener on `TenantCreated` only

**Description:** Listen for domain event and provision RBAC without tightening call sites.  
**Pros:** Decoupled.  
**Cons:** Easy to miss non-event paths; actor/owner context must be explicit.  
**Not chosen as sole mechanism.

### Option C: Central provisioner + explicit call from tenant creation ✓ Selected

**Description:** Introduce **`Modules\Tenancy\Services\TenantRbacProvisioner`** as the **single catalog source**. Invoke it from **`UserService::createPersonalTenant`** (after owner `tenant_users` row exists) and refactor **`RbacCatalogSeeder`** to use the same class. **`assignTenantAdminRole`** requires an **active owner** membership or throws **`InvalidArgumentException`**. Team context uses **`try` / `finally`** so **`setPermissionsTeamId(null)`** always runs.

**Pros:** One matrix; seed and runtime stay aligned; misuse fails fast; auditable code path.  
**Cons:** Callers must create membership before assigning admin; org tenants created without this path need a follow-up if product requires parity.

---

## 3. Decision

1. **`TenantRbacProvisioner`** owns the **global permission list** and **per-tenant role matrices**; do not duplicate catalogs in seeders.
2. **Personal tenant creation:** After **`TenantUser`** owner row is persisted, run **`ensureGlobalPermissions`**, **`ensureRolesForTenant`**, **`assignTenantAdminRole`** (in that order).
3. **Seeding:** **`RbacCatalogSeeder`** calls the same provisioner for every existing **central** tenant, then assigns **`tenant-admin`** to the configured admin user’s personal tenant via **`assignTenantAdminRole`**.
4. **`assignTenantAdminRole`** is **owner-gated** (active **`tenant_users`** with `membership_type = owner`). Promoting non-owners to **`tenant-admin`** continues to use existing tenant admin flows (e.g. role sync under tenancy middleware).
5. **Frontend navigation** for the Inertia shell uses **`router.visit(route(...))`** (or **`Link`**) for drawer items; **`globalThis.Ziggy`** is set from the generated Ziggy config in **`resources/js/app.js`**.

---

## 4. Consequences

### Positive

- Registrants and any code path using **`createPersonalTenant`** get consistent RBAC.
- Catalog drift between seed and runtime eliminated.
- Permission cache / team-id leaks reduced via **`finally`** cleanup and idempotent **`ensureGlobalPermissions`** (missing names only).

### Tradeoffs

- **Organization** (or other) tenants created **only** with **`addUserToTenant(..., 'owner')`** do **not** automatically get role rows unless a future change calls **`ensureRolesForTenant`** + **`assignTenantAdminRole`** (or equivalent). Product backlog owns that extension.
- **`TenantRbacProvisioner`** becomes a **dependency** of Identity **`UserService`** (creation orchestration); acceptable for personal-tenant lifecycle.

---

## 5. Risks & Controls

| Risk | Control |
|------|---------|
| Caller assigns admin without owner row | **`InvalidArgumentException`** in **`assignTenantAdminRole`** |
| Stale Spatie team id after exception | **`try` / `finally`** around **`setPermissionsTeamId`** |
| Duplicate permission names | **`ensureGlobalPermissions`** uses diff vs DB; **`firstOrCreate`** for missing |
| Over-broad tenant-admin on org invite | Not in scope; use explicit invite/RBAC policy when org flows mature |

---

## 6. Enforcement Impact (for governance sync only)

- **`PROJECT_MANIFEST.md`** — **`PHASE3B-RBAC`** lock extended with a **provisioning** bullet referencing this ADR.
- **`LESSONS.md`** — Short entries for Inertia navigation + provisioning pattern.

---

## 7. References

- `.cursor/memory/LESSONS.md` — Inertia drawer; provisioning pattern.
- `Modules/Tenancy/app/Services/TenantRbacProvisioner.php`
- `Modules/Identity/app/Services/UserService.php` — `createPersonalTenant`
- `database/seeders/RbacCatalogSeeder.php`
- ADR-0001 (tenancy engine; membership vs roles discipline).
