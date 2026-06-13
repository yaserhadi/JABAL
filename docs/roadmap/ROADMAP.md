# Product Roadmap

High-level direction for Jabal (SaaS core platform). **Current execution** for active work: [JABAL Core Realignment](../reports/JABAL_CORE_REALIGNMENT.md), `.cursor/memory/STATE.yaml`, and `.cursor/memory/HANDOFF.md`. Closure evidence lives under `.cursor/reports/` and `docs/reports/`.

## Current initiative (2026-05-28)

**Phase 4 re-home (foundation-first)** — [plan](../../.cursor/plans/phase_4_re-home_revised_9d6e261c.plan.md). Re-home legacy 4A/4B/4C goals under ADR-0007 after F1–F8; salvage legacy branches, never merge them.

| Item | Value |
|------|--------|
| LKGS | `main` @ `4f40f0b` (106 tests pass) |
| Active branch | `feature/core-realignment-foundation` |
| Lock | `PLATFORM-TENANT-SEPARATION` (Active) |
| Done | Stages 0–2.5; Stage 3 design/docs; Wave 0 baseline |
| In progress | Wave 0.5 doc alignment; Wave 1 F1–F8 |
| Next | Wave 1.5 salvage audit → Wave 2 (4A Billing) → Wave 3 (4B) → Wave 4 (4C charter) |
| Legacy Phase 4 branches | Salvage only — `feature/phase-4a-billing-plans`, `feature/phase-4b-enterprise-access`, `feature/mfa-*` |

The **Phase overview** below is the **historical roadmap** (Phase 1–5 on `main` and in MANIFEST). Do **not** label Core Realignment stages as “Phase 3” or “Phase 5” in new docs.

## Execution status (source of truth)

| Question | Where to read |
|----------|----------------|
| What are we doing *now*? | [JABAL_CORE_REALIGNMENT.md](../reports/JABAL_CORE_REALIGNMENT.md), `STATE.yaml`, `HANDOFF.md`, Phase 4 re-home plan |
| Why does the project exist (strategy)? | `.cursor/goals/GOALS.md` |
| Locks, ADRs, governance | `PROJECT_MANIFEST.md`, `docs/architecture/ADR/` |

---

## Phase overview (legacy roadmap — historical)

- **Phase 1:** Technical foundation, modular boundaries, API surface, UI (Inertia/Vue/Vuetify).
- **Phase 2:** Stancl tenancy, dual-database architecture, identification and security locks.
- **Phase 3:** Domain layer + RBAC + tenant admin surfaces (split for delivery):
  - **3A — Domain foundation** — First domain tables in `jabal_tenant_shared`, `BelongsToTenant`, cross-tenant isolation tests (ongoing as new domains land).
  - **3B — RBAC** — Tenant-aware Spatie permissions, roles scoped by tenant, API/web enforcement (delivered; extend only via catalog changes).
  - **3C — Workspaces & membership** — Workspace CRUD, tenant member management, tenant-scoped web/API (delivered).
  - **3D — Tenant settings** — Central `tenant_settings`, branding/timezone/locale, web + API, RBAC + audit (delivered).
  - **3E — Next domain** — **Deferred** until Phase 4 re-home completes (R6).
- **Phase 4:** Enterprise & scale readiness — **re-homed after F1–F8**, not legacy branch merge:
  - **4A** — Subscription/billing + plans (Wave 2).
  - **4B** — Enterprise access (SSO, MFA, rate limits, session controls) (Wave 3).
  - **4C** — Observability, DR/BCP, performance (Wave 4 charter only).
- **Phase 5:** Platform productization & tenant evolution:
  - **5A** — Productization layer (core vs product, lifecycle).
  - **5B** — Multi-product / multi-app readiness.
  - **5C** — Advanced isolation (`isolation_level`: shared/schema/database, migration paths).

---

## Backlog & deferred capabilities

Items below are **not** execution checklists; they are strategic deferrals inherited from baseline planning. Sequencing may change; verify against `STATE.yaml` before starting work.

### Still open (product / platform)

- **User preferences** — Per-user settings (not tenant settings); no dedicated phase table in central DB for this yet.
- **Phase 4A —** Subscription/billing + plans (Wave 2 after F1–F8).
- **Phase 4B —** SSO + advanced security (Wave 3).
- **Phase 4C —** Observability + DR/BCP + performance (Wave 4 charter).
- **Phase 5A —** Platform productization (reusable foundation, starter modules, templates).
- **Phase 5B —** Multi-product / multi-app readiness.
- **Phase 5C —** Advanced isolation strategy (promote shared → schema/database, data mobility).

### Process / quality

- **API conventions** — Optional fields / envelope details (open).
- **Formal UAT** — Documented UAT execution (unverified).

### Notes on former “deferred” items (now delivered or superseded)

- **Tenant-aware RBAC (ex Phase 3B)** — Delivered; catalog extended over time (e.g. workspace, member, tenant settings permissions).
- **Tenant-level settings (ex Phase 3D)** — Delivered as central `tenant_settings` + Tenancy module; scope lock on columns per plan.
- **Broader 3A domain foundation** — Workspaces are one domain; additional entities continue under Phase 3E+ (deferred).
- **Modular monolith module boundaries (Phase 1 alignment)** — **ADR-0003** (Final) plus Phase A/B record in **`docs/reports/MODULE_BOUNDARY_AUDIT.md`**; new modules follow **MODULE-CREATION-GATE** (`.cursor/rules/module-creation-gate.mdc`). Syncing boundary language into `PROJECT_MANIFEST.md` remains **human-approved** (ADR-0003 §6).

---

## Reference

See [JABAL_CORE_REALIGNMENT.md](../reports/JABAL_CORE_REALIGNMENT.md) for the canonical stage map vs this historical phase numbering.
