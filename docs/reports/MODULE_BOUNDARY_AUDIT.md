# Module boundary audit report

**Date:** 2026-03-29  
**Branch:** `feature/module-boundary-audit`  
**Scope:** Phase A evidence-only (read-first). Aligned with [ADR-0003](../architecture/ADR/ADR-0003-modular-monolith-module-boundaries.md) and **MODULE-CREATION-GATE** (`kss-framework/rules/module-creation-gate.mdc`).  
**Layer E:** Executed (brief).

> **Note:** A working copy may also exist under `.cursor/reports/` (often gitignored). This `docs/reports/` path is the **version-controlled** mirror.

---

## 1. Executive summary

Tenant-facing **HTTP routes** are overwhelmingly **module-owned** (`Modules/*/routes/*.php`) with **`routes/web.php` bootstrap-only** (root redirect). The **API** entry file is **`Modules/Api/routes/api.php`**, registered from `bootstrap/app.php`, and handlers are **module controllers**. No active **feature routes** were found outside those locations.

Phase A identified **hygiene-only** gaps (legacy controllers, orphan `Welcome.vue`, duplicate Identity controllers, unused `AuditLogController`, disabled Audit nav). **Phase B** addressed them in four small batches (§6): removed dead files, deleted orphan page, and **documented** the Audit drawer item as an intentional tenant-vs-platform placeholder—not wired to `/admin/audit` without product/gating work.

**Layer D** remains: **no** `app/Services`, `app/Http/Requests`, `app/Policies`, `app/Actions`, or `app/UseCases` trees—`app/Support` and providers fit **kernel-acceptable** usage.

---

## 2. Findings table

*Rows below reflect the **Phase A** snapshot. Paths marked **Legacy unused** in rows 36–40, 43–44 were **removed** in Phase B batches 1–3 (§6).*

| Path / artifact | Layer | Classification | Evidence |
|-----------------|-------|----------------|----------|
| `routes/web.php` | A | **Bootstrap-only** | Single `Route::get('/')` redirect; comment lock matches ADR-0003 |
| `routes/console.php` | A | **Compliant** (non-UI) | Artisan commands only |
| `bootstrap/app.php` → `Modules/Api/routes/api.php` | A / E | **Compliant** | API surface mounted from Api module file; `/up` health is framework default |
| `Modules/Identity/routes/web.php` | A | **Compliant** | Auth + tenant `/t/{tenant}/...` (dashboard, members) |
| `Modules/Tenancy/routes/web.php`, `tenant_web.php`, `api.php` | A | **Compliant** | Tenancy web + API |
| `Modules/Workspaces/routes/web.php`, `api.php` | A | **Compliant** | Workspace web + API (via Api module mount for API) |
| `Modules/Settings/routes/web.php`, `api.php` | A | **Compliant** | Platform admin `/admin/settings` + API |
| `Modules/Audit/routes/web.php`, `api.php` | A | **Compliant** | Platform admin `/admin/audit` + API |
| `Modules/Api/routes/web.php` | A | **Compliant** | `apis` resource (module-owned) |
| `app/Http/Controllers/Controller.php` | B | **Kernel-acceptable** | Base class extended by module controllers |
| `app/Http/Controllers/DashboardController.php` | B | **Legacy unused** | `Inertia::render('Dashboard')` but **no** `Route::` reference; dashboard served from `Modules/Identity/routes/web.php` closure |
| `app/Http/Controllers/Auth/LoginController.php` | B | **Legacy unused** | **No** route binding; Identity uses `AuthController` |
| `app/Http/Controllers/Auth/RegisterController.php` | B | **Legacy unused** | Same |
| `Modules/Identity/.../LoginController.php`, `RegisterController.php`, `LogoutController.php` | B | **Legacy unused** | **No** route references; `AuthController` owns auth UI |
| `Modules/Audit/.../AuditLogController.php` | B | **Legacy unused** | **No** route reference; routes use `AuditController` |
| `resources/js/Pages/Dashboard.vue` | C | **Compliant** | Used via `Inertia::render('Dashboard')` in Identity `web.php` |
| `resources/js/Pages/Auth/*`, Workspaces, Members, TenantSettings | C | **Compliant** | Matched to module `Inertia::render` call sites |
| `resources/js/Pages/Welcome.vue` | C | **Legacy unused** | **No** `Inertia::render('Welcome')` in PHP grep |
| `resources/js/Pages/Settings/Index.vue` | C | **Compliant** | Platform admin Settings module (`/admin/settings`) |
| `resources/js/Pages/Audit/*` | C | **Compliant** | `AuditController` + routes |
| `resources/js/Layouts/AppLayout.vue` (drawer) | C | **Compliant** | `visitTenantRoute('dashboard' \| 'workspaces.index' \| 'members.index' \| 'tenant.settings.index')` — module-named routes |
| `AppLayout.vue` — “Audit Logs” item | C | **Kernel-acceptable / product stub** | `disabled`; not pointing at a wrong route—intentional placeholder |
| `app/Support/**`, `app/Models/User.php`, middleware, exceptions | D | **Kernel-acceptable** | Cross-cutting context, contracts, tenancy middleware, shared `User` |
| `app/Services`, `app/Http/Requests`, `app/Policies`, `app/Actions`, `app/UseCases` | D | **N/A** | Directories absent or empty at audit date |
| `app/Providers/AppServiceProvider.php` | E | **Kernel-acceptable** | Context singletons, Stancl API header config, Spatie team subscriber |
| `app/Providers/TenancyServiceProvider.php` | E | **Kernel-acceptable** | Stancl listener wiring; shared-DB architecture documented |
| `bootstrap/providers.php` | E | **Kernel-acceptable** | Registers app providers only |

---

## 3. Explicit “no action” (reviewed)

- **Module route files** for Identity, Tenancy, Workspaces, Settings, Audit, Api — keep as primary feature surfaces.
- **`app/`** middleware stack, `HandleInertiaRequests`, validation/token middleware — kernel.
- **`AppServiceProvider` / `TenancyServiceProvider`** — platform glue; not moved to a feature module without a separate ADR-level decision.

---

## 4. Flags / watch items (not necessarily violations)

- ~~Duplicate Identity auth controllers~~ — **resolved** (Phase B batch 2); `AuthController` remains the single auth entry.
- **Audit UI in drawer** — **documented** in `AppLayout.vue` (Phase B batch 4); optional future work: wire with `platform.admin` gating to `/admin/audit` (item 5 below).

---

## 5. Phase B backlog (execute only after human approval)

**Status:** Items **1–4 completed** 2026-03-29 on `feature/module-boundary-audit` (four commits). **`php artisan test`:** 89 passed.

1. ~~**Delete** dead `app/Http/Controllers` auth + dashboard~~ — done (batch 1).
2. ~~**Delete** orphan `Welcome.vue`~~ — done (batch 3).
3. ~~**Remove** unused Identity `Login`/`Register`/`Logout` controllers~~ — done (batch 2).
4. ~~**Remove** unused `AuditLogController`~~ — done (batch 2); routes continue to use `AuditController`.
5. **Optional UX:** Enable drawer Audit entry with **platform-admin** route and permission check — **deferred** (not required for boundary hygiene).

**Rules reminder:** One concern per commit; any move `app/` → `Modules/<Name>/` must match **MODULE-CREATION-GATE** destination and ADR-0003.

---

## 6. Phase B execution status

Executed in **four small batches** on branch `feature/module-boundary-audit` (see git history). No large `app/` → `Modules/` moves; only **legacy unused** removals and documented UI intent.

| Batch | Scope |
| ----- | ----- |
| 1 | Removed dead `app/Http/Controllers` auth + dashboard controllers |
| 2 | Removed unused Identity (`Login`/`Register`/`Logout`) and Audit `AuditLogController` |
| 3 | Removed orphan `Welcome.vue` (no `Inertia::render('Welcome')`) |
| 4 | Documented **Audit Logs** drawer item as **intentional placeholder** (tenant shell vs `/admin/audit` product boundary) |

---

## 7. Sign-off (Phase A adopted)

**Phase A is formally adopted.** Human judgment:

> The audit confirms that current practice is broadly aligned with ADR-0003 and MODULE-CREATION-GATE, with cleanup limited to legacy unused files and minor hygiene items.

- [x] Phase A classifications accepted; **Legacy unused** / **Phase B** order confirmed  
- [x] Post–Phase B: route/controller grep smoke check re-run (no stale references to removed controllers)

**Auditor notes:** Initial Phase A was automated read-only; Phase B hygiene applied per approved batch sequence—no mixing with Phase 3D/4 scope.
