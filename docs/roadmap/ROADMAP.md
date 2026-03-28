# Product Roadmap

High-level direction for Jabal (SaaS core platform). **Current execution phase, branch status, and session next steps** live in `.cursor/memory/STATE.yaml` and `.cursor/memory/HANDOFF.md`. Closure evidence lives under `.cursor/reports/`.

## Execution status (source of truth)

| Question | Where to read |
|----------|----------------|
| What are we doing *now*? | `STATE.yaml`, `HANDOFF.md` |
| Why does the project exist (strategy)? | `.cursor/goals/GOALS.md` |
| Locks, ADRs, governance | `PROJECT_MANIFEST.md`, `docs/architecture/ADR/` |

---

## Phase overview

- **Phase 1:** Technical foundation, modular boundaries, API surface, UI (Inertia/Vue/Vuetify).
- **Phase 2:** Stancl tenancy, dual-database architecture, identification and security locks.
- **Phase 3:** Domain layer + RBAC + tenant admin surfaces (split for delivery):
  - **3A — Domain foundation** — First domain tables in `jabal_tenant_shared`, `BelongsToTenant`, cross-tenant isolation tests (ongoing as new domains land).
  - **3B — RBAC** — Tenant-aware Spatie permissions, roles scoped by tenant, API/web enforcement (delivered; extend only via catalog changes).
  - **3C — Workspaces & membership** — Workspace CRUD, tenant member management, tenant-scoped web/API (delivered).
  - **3D — Tenant settings** — Central `tenant_settings`, branding/timezone/locale, web + API, RBAC + audit (delivered).
  - **3E — Next domain** — Next aggregate under `jabal_tenant_shared` + RBAC + isolation tests (planned; see `HANDOFF.md`).
- **Phase 4:** Enterprise & scale readiness:
  - **4A** — Subscription/billing + plans.
  - **4B** — Enterprise access (SSO, MFA, rate limits, session controls).
  - **4C** — Observability, DR/BCP, performance.
- **Phase 5:** Platform productization & tenant evolution:
  - **5A** — Productization layer (core vs product, lifecycle).
  - **5B** — Multi-product / multi-app readiness.
  - **5C** — Advanced isolation (`isolation_level`: shared/schema/database, migration paths).

---

## Backlog & deferred capabilities

Items below are **not** execution checklists; they are strategic deferrals inherited from baseline planning. Sequencing may change; verify against `STATE.yaml` before starting work.

### Still open (product / platform)

- **User preferences** — Per-user settings (not tenant settings); no dedicated phase table in central DB for this yet.
- **Phase 4A —** Subscription/billing + plans (usage limits, billing lifecycle).
- **Phase 4B —** SSO + advanced security (e.g. SAML/OIDC, MFA policies).
- **Phase 4C —** Observability + DR/BCP + performance (tenant-aware logging, backups, indexing).
- **Phase 5A —** Platform productization (reusable foundation, starter modules, templates).
- **Phase 5B —** Multi-product / multi-app readiness.
- **Phase 5C —** Advanced isolation strategy (promote shared → schema/database, data mobility).

### Process / quality

- **API conventions** — Optional fields / envelope details (open).
- **Formal UAT** — Documented UAT execution (unverified).

### Notes on former “deferred” items (now delivered or superseded)

- **Tenant-aware RBAC (ex Phase 3B)** — Delivered; catalog extended over time (e.g. workspace, member, tenant settings permissions).
- **Tenant-level settings (ex Phase 3D)** — Delivered as central `tenant_settings` + Tenancy module; scope lock on columns per plan.
- **Broader 3A domain foundation** — Workspaces are one domain; additional entities continue under Phase 3E+.
- **Modular monolith module boundaries (Phase 1 alignment)** — **ADR-0003** (Final) plus Phase A/B record in **`docs/reports/MODULE_BOUNDARY_AUDIT.md`**; new modules follow **MODULE-CREATION-GATE** (`kss-framework/rules/module-creation-gate.mdc`). Syncing boundary language into `PROJECT_MANIFEST.md` remains **human-approved** (ADR-0003 §6).

---

## Reference

- Strategic goals (why): `.cursor/goals/GOALS.md`
- Project state: `.cursor/memory/STATE.yaml`
- Reports: `.cursor/reports/`
