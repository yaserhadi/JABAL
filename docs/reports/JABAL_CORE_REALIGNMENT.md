# JABAL Core Realignment

**Status:** In progress  
**Branch:** `feature/platform-tenant-separation`  
**ADR:** [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md)  
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

| Stage | Name | Scope | Status (2026-05-26) |
|-------|------|--------|---------------------|
| **0** | Governance + ADR | ADR-0007, lock, module boundary, branch policy | Done (lock Active) |
| **1** | Stabilization | Test gate, API 401/403 contract, suite green | **CLOSED** — [TEST_STABILIZATION_GATE.md](TEST_STABILIZATION_GATE.md) |
| **2** | Platform / tenant separation | `PlatformUser`, `TenantUser`, routes, guards, shared_db users | **Done** (2026-05-26 — `382fcbb`…`1fba242`) |
| **3** | Tenancy abstraction | `TENANCY_MODE`, `TenantStorageResolver`, `.env.example`, Appendix A | Design/docs done |
| **4** | Identity split (deep) | Full cutover, deprecations, membership model clarity | Not started |
| **5** | Provisioning model | `TenantDatabaseProvisioner`, per-tenant DB/schema runtime | Not started |
| **6** | Re-introduce SaaS capabilities | Billing, SSO, MFA, etc. on **new** architecture — not legacy branch merge | Not started |

**Do not** label Stages 0–6 as “Phase 3” or “Phase 5” in new docs — those numbers are reserved for the historical roadmap in `PROJECT_MANIFEST.md`.

---

## Agent rules

1. Do **not** merge legacy Phase 4 branches as-is.  
2. Do **not** reintroduce central `users` as tenant login for new installs.  
3. Use **Stage N** for Core Realignment; use **legacy Phase N** only when referring to historical roadmap or old branches.  
4. See ADR-0007 **Supersession notice** before reusing code from experimental branches.  
5. **Docs-only** changes must not alter runtime behavior unless a separate task says so.

---

## References

- [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md)
- [PLATFORM_TENANT_SEPARATION_REPORT.md](PLATFORM_TENANT_SEPARATION_REPORT.md)
- [TEST_STABILIZATION_GATE.md](TEST_STABILIZATION_GATE.md)
- [MODULE-BOUNDARY-PLATFORM-TENANT.md](../architecture/MODULE-BOUNDARY-PLATFORM-TENANT.md)
