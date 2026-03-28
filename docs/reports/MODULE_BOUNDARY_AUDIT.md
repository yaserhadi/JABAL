# Module boundary audit report

**Date:** 2026-03-29  
**Branch:** `feature/module-boundary-audit`  
**Scope:** Phase A evidence-only (read-first). Aligned with [ADR-0003](../architecture/ADR/ADR-0003-modular-monolith-module-boundaries.md) and **MODULE-CREATION-GATE** (`kss-framework/rules/module-creation-gate.mdc`).  
**Layer E:** Executed (brief).

> **Note:** A working copy may also exist under `.cursor/reports/` (often gitignored). This `docs/reports/` path is the **version-controlled** mirror.

---

## 1. Executive summary

Tenant-facing **HTTP routes** are overwhelmingly **module-owned** (`Modules/*/routes/*.php`) with **`routes/web.php` bootstrap-only** (root redirect). The **API** entry file is **`Modules/Api/routes/api.php`**, registered from `bootstrap/app.php`, and handlers are **module controllers**. No active **feature routes** were found outside those locations.

The main gaps are **hygiene**: **unreferenced** Laravel-style controllers under `app/Http/Controllers` (superseded by `Modules\Identity\Http\Controllers\AuthController`), **orphan** `Welcome.vue`, **duplicate/unused** controllers inside `Modules/Identity`, and **`AuditLogController`** in Audit module with **no route binding**. **Navigation** in `AppLayout.vue` uses **named Ziggy routes** for tenant surfaces; **Audit Logs** is **disabled** (placeholder, not a broken link). **Layer D** found **no** `app/Services`, `app/Http/Requests`, `app/Policies`, `app/Actions`, or `app/UseCases` trees—`app/Support` and providers fit **kernel-acceptable** usage.

**Phase B** was **not executed** in this pass; backlog is listed in §5 for human approval.

---

## 2. Findings table

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

- **Duplicate auth controller patterns** in Identity module (`LoginController` / `RegisterController` vs `AuthController`) — confusing for contributors; cleanup is **hygiene**.
- **Audit UI in drawer** disabled while Audit module exists under `/admin/audit` — product choice; enabling would need `visit` to admin routes or role-gated nav (out of scope for this audit).

---

## 5. Phase B backlog (execute only after human approval)

Ordered per plan: **dead code first**, then structural moves (none identified in `app/` for this audit).

1. **Delete** (after second-pass grep + test run): `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/Auth/LoginController.php`, `app/Http/Controllers/Auth/RegisterController.php` — if still unreferenced.
2. **Delete or document** `resources/js/Pages/Welcome.vue` — orphan page.
3. **Remove or consolidate** unused Identity controllers: `LoginController`, `RegisterController`, `LogoutController` under `Modules/Identity` — single auth entry (`AuthController`) preferred.
4. **Remove or route** `Modules/Audit/Http/Controllers/AuditLogController.php` — either register routes or delete if superseded by `AuditController`.
5. **Optional UX:** Enable drawer Audit entry with correct **platform-admin** route and permission check (separate small change).

**Rules reminder:** One concern per commit; any move `app/` → `Modules/<Name>/` must match **MODULE-CREATION-GATE** destination and ADR-0003.

---

## 6. Phase B execution status

**Not run** in this audit session — per plan, Phase B follows human sign-off on classifications above.

---

## 7. Sign-off

- [ ] Human reviewer confirms **Legacy unused** and **Phase B** item order  
- [ ] After cleanup, re-run Layer A/B grep smoke check  

**Auditor notes:** Automated agent pass; no runtime or schema changes performed.
