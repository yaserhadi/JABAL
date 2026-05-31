# JABAL Core Realignment

**Status:** Stage **2 + 2.5 CLOSED** — merged to **local `main`** @ `f77a2e6` (2026-05-30). UAT PASS ([UAT_STAGE_1_2_2_5.md](UAT_STAGE_1_2_2_5.md), 2026-05-28). The previous “approved for PR” wording is superseded by the local merge state. **Remote PR/push status should be verified separately** before treating the work as merged upstream.  
**Branch:** `main` (local merge from `feature/platform-tenant-separation`)  
**ADR:** [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md) (§3.1.1–§3.1.5 isolated runtime boundaries) — **Draft**  
**Lock:** `PLATFORM-TENANT-SEPARATION` (Active)

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
| **Legacy Phase 4** (4A/4B/MFA branches) | **Not merged** — superseded; concepts reusable after Stage 6 |
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
| **3** | Tenancy abstraction | `TENANCY_MODE`, `TenantStorageResolver`, `.env.example`, Appendix A | Design/docs done (Suite F smoke pass in UAT; runtime work not started) |
| **4** | Identity split (deep) | Full cutover, `platform_roles` tables, deprecations, membership clarity | Not started — eligible on local `main`; owner scope required |
| **5** | Provisioning model | `TenantDatabaseProvisioner`, per-tenant DB/schema runtime | Not started |
| **6** | Re-introduce SaaS capabilities | Billing, SSO, MFA, etc. on **new** architecture — not legacy branch merge | Not started |

**Stage 2 + 2.5 closed** 2026-05-28 when UAT sign-off completed (G1–G11, Suites A–F). **Merged to local `main`** 2026-05-30 (`f77a2e6`). **Next:** Stage 3+ planning (owner scope). Verify remote upstream separately if applicable.

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
