# JABAL Core Realignment

**Status:** Stage **2 + 2.5 CLOSED** — **local `main`** @ **`4f40f0b`** (2026-05-28 LKGS). UAT PASS ([UAT_STAGE_1_2_2_5.md](UAT_STAGE_1_2_2_5.md), 2026-05-28).  
**Branch:** `main` (LKGS); active work on `feature/core-realignment-foundation`  
**ADR:** [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md) (§3.1.1–§3.1.5 isolated runtime boundaries) — **Draft**  
**Lock:** `PLATFORM-TENANT-SEPARATION` (Active)  
**Current initiative:** **Phase 4 re-home (foundation-first)** — legacy 4A/4B/4C goals re-homed after F1–F8, not by merging legacy branches.

---

## Why this name

This initiative is **not**:

- A continuation of **legacy roadmap Phase 4** (unmerged experimental branches), or  
- A reuse of **legacy roadmap Phase 3** (already merged to `main` for domain + RBAC).

Legacy Phase 4 exposed that platform/tenant identity and storage assumptions were unstable. Execution paused; architecture was redefined. Current work **rebuilds the SaaS core** before re-introducing enterprise features.

**Canonical label:** **JABAL Core Realignment**

---

## Relationship to legacy roadmap phases

| Label | Meaning |
|-------|---------|
| **Legacy Phase 3** (MANIFEST) | Domain + tenant-aware RBAC on `main` — **operational**, unchanged |
| **Legacy Phase 4** (4A/4B/MFA branches) | **Not merged** — **re-homed after F1–F8** via [Phase 4 re-home plan](../../.cursor/plans/phase_4_re-home_revised_9d6e261c.plan.md); salvage only, never branch merge |
| **Core Realignment** | Current track — platform/tenant split, tenancy abstraction, provisioning, then safe re-introduction of SaaS capabilities |

Historical phase numbers in `PROJECT_MANIFEST.md` are **not renumbered**.

---

## Stage map (current track)

| Stage | Name | Scope | Status |
|-------|------|--------|--------|
| **0** | Governance + ADR | ADR-0007, lock, module boundary, branch policy | Done (lock Active) |
| **1** | Stabilization | Test gate, API 401/403 contract, suite green | **CLOSED** — [TEST_STABILIZATION_GATE.md](TEST_STABILIZATION_GATE.md) |
| **2** | Platform / tenant separation (logical) | `PlatformUser`, `TenantUser`, routes, guards, shared_db users | **CLOSED** — [UAT_STAGE_1_2_2_5.md](UAT_STAGE_1_2_2_5.md) (Suites A–F, G1–G11) |
| **2.5** | Runtime separation hardening | `platform_sessions`, `ConfigureApplicationRuntime`, `platform.web` / `tenant.web`, distinct cookies; RBAC placement defined | **CLOSED** — Suite C pass (2026-05-28) |
| **3** | Tenancy abstraction | `TENANCY_MODE`, `TenantStorageResolver`, `.env.example`, Appendix A | **Design/docs done** — resolver bound on `main`; F3 = adoption audit in Wave 1 |
| **4** | Identity split (deep) | `platform_roles`, membership on tenant layer, `tenant_contacts`, deprecate central `tenant_users` | **In progress** — Wave 1 (`feature/core-realignment-foundation`); not the historical “Stage 4 megaproject” label |
| **5** | Provisioning model | `TenantDatabaseProvisioner`, per-tenant DB/schema runtime | Stub on `main`; full runtime not started |
| **6** | Full SaaS productization | `database_per_tenant` provisioning, advanced isolation | Not started — Phase 4A/4B re-home on `shared_db` explicitly in scope once F1–F8 pass |

**Stage 2 + 2.5 closed** 2026-05-28 (UAT PASS). **LKGS:** `main` @ `4f40f0b` (106 tests). **Next:** Phase 4 re-home Wave 1 (F1–F8) on `feature/core-realignment-foundation`.

Legacy Phase 4 **goals** (4A billing, 4B enterprise, 4C observability) are re-homed **after** critical foundation gates (F1–F8), not by merging legacy branches and not necessarily waiting for full Stage 5/6 infrastructure.

**Do not** label Stages 0–6 as “Phase 3” or “Phase 5” in new docs — those numbers are reserved for the historical roadmap in `PROJECT_MANIFEST.md`.

---

## UAT status (Stages 1 + 2 + 2.5)

**Canonical checklist:** [UAT_STAGE_1_2_2_5.md](UAT_STAGE_1_2_2_5.md) — **PASS** (2026-05-28). Evidence: owner `UAT1.csv` transcribed in UAT doc. Local merge to `main` @ `f77a2e6` supersedes prior “approved for PR” execution wording.

| Suite | Scope | Status |
|-------|--------|--------|
| **A** | Platform Management (login, settings, audit, logout) | **Pass** |
| **B** | Tenant Application web (register, dashboard, login, members) | **Pass** |
| **C** | Platform ↔ Tenant isolation (routes + **runtime sessions** C1–C9) | **Pass** (C9 corroborated by automated test) |
| **D** | API auth contract (401/403) | **Pass** |
| **E** | Tenant RBAC | **Pass** |
| **F** | Tenancy smoke (`shared_db`) | **Pass** |

**Automated gate:** 106 passed / 0 failed (pre-merge verification on `feature/platform-tenant-separation` @ `ed7decb`; see UAT doc).

---

## Agent rules

1. Do **not** merge legacy Phase 4 branches as-is.  
2. Do **not** reintroduce central `users` as tenant login for new installs.  
3. Use **Stage N** for Core Realignment; use **legacy Phase N** only when referring to historical roadmap or old branches.  
4. See ADR-0007 **Supersession notice** and **§3.1.1–§3.1.5** before auth/session/RBAC changes.  
5. **Stage 2.5** is a **Stage 2 UAT closure gap**, not a new independent roadmap phase or Stage 3.5.

---

## References

- [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md)
- [PLATFORM_TENANT_SEPARATION_REPORT.md](PLATFORM_TENANT_SEPARATION_REPORT.md)
- [TEST_STABILIZATION_GATE.md](TEST_STABILIZATION_GATE.md)
- [UAT_STAGE_1_2_2_5.md](UAT_STAGE_1_2_2_5.md)
- [MODULE-BOUNDARY-PLATFORM-TENANT.md](../architecture/MODULE-BOUNDARY-PLATFORM-TENANT.md)
