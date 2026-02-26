# ADR-0001: Multi-tenancy Approach

Status: **Final**  
Date: 2026-02-21  
Owner: KSS Steward - YH  
**Decision:** Adopt stancl/tenancy in Phase 2

---

## 1. Context

The project requires multi-tenancy for SaaS isolation. The original target was `stancl/tenancy`. The codebase currently uses a custom implementation (TenantResolverMiddleware, TenantContext) with a shared database and row-level isolation.

---

## 2. Options Considered (Historical)

### Option A: Adopt `stancl/tenancy` ✓ Selected
**Description:** Migrate the current custom implementation to the `stancl/tenancy` package.
**Pros:** Standard approach; community support; maintained patterns.
**Cons:** Migration effort; possible breaking changes.
**Risks:** Regression; downtime; incomplete migration.

### Option B: Ratify custom
**Description:** Formalize and document the existing custom approach.
**Pros:** No migration; minimal disruption.
**Cons:** Ongoing maintenance burden; less alignment with ecosystem patterns.
**Risks:** Design drift; security/SoD blind spots; future refactor cost.

### Option C: Hybrid
**Description:** Partial adoption of `stancl/tenancy` while retaining some custom logic.
**Pros:** Balance of standardization and flexibility.
**Cons:** Higher complexity; potential inconsistency.
**Risks:** Conflicting tenancy sources; integration complexity; unclear ownership.

---

## 3. Decision

**Final: Adopt stancl/tenancy in Phase 2.**

The tenancy engine for Phase 2 is stancl/tenancy. This decision is non-negotiable for the migration roadmap. Phase 1.5 is a bridge/cleanup phase to enable safe migration; Phase 2 performs the engine switch.

### Rationale (Evidence-Based)

- **Resolver duplication:** Custom implementation has two resolvers (TenantResolver service and TenantResolverMiddleware) with no single source of truth. Middleware does not use the injected TenantResolverInterface.
- **Broken/incomplete mechanisms:** Session key `active_tenant_id` is read but never written; `DomainEvent::captureTenantId()` returns null (TODO); `expectsJson()` gates API token logic with edge-case risk.
- **Maintenance:** stancl/tenancy provides maintained, standard patterns and community support.
- **Migration risk:** Phase 1.5 roadmap (`.cursor/reports/PHASE1_5_TENANCY_TRANSITION_ROADMAP.md`) documents cleanup plan, breakage risks, and mitigation to reduce regression.

---

## 4. Consequences

### Positive Outcomes

- Ecosystem alignment with Laravel tenancy patterns
- Maintained package with community support
- Standard identification methods (domain, subdomain, path, request data)
- Clear separation of concerns via stancl middleware and resolvers

### Negative Tradeoffs

- Migration effort and CAB review required before Phase 2 execution
- Middleware, context, and helper changes
- Test updates to align with stancl test helpers
- Possible short-term regression risk during migration

### Operational Impact

- Phase 2 migration must follow CAB governance
- Rollback plan required before Phase 2 execution
- Regression validation mandatory for tenant resolution and scoping

### Preserved Domain Logic (Must Keep)

- `tenant_users` membership model: `membership_type` (owner, admin, member, customer), `status` (active, invited, suspended)
- Token ability format `tenant:{uuid}` for API scoping
- Personal tenant concept (`User::personalTenant`, `UserService::getPersonalTenant`)
- Tenant type (personal/organization) and `isolation_level` (shared/schema/database)
- `UserService::createPersonalTenant`, `addUserToTenant`, membership operations

### Identification Method (Phase 2) — Resolved in Phase 1.5

- **Phase 2 Identification Decision:** Path (Web) + RequestData (API). See `.cursor/reports/PHASE1_5_TENANCY_TRANSITION_ROADMAP.md` section "Phase 2 Identification Decision" for rules and criteria.

---

## 5. Risks & Controls

**Known risks:** See option risks above; breakage risk map in Phase 1.5 roadmap.

**Controls (in effect):**

- Any tenancy-related change must be triaged via governance workflow (risk-sensitive change control).
- Maintain a single authoritative tenant-resolution path (avoid parallel sources).
- Require regression validation for tenant resolution and tenant scoping to prevent cross-tenant access.
- Phase 2 execution requires CAB approval and rollback plan.

---

## 6. Enforcement Impact (For KSS Sync Only)

When syncing with PROJECT_MANIFEST / INTEGRITY_RULES:

- **Architectural lock:** Tenancy engine = stancl/tenancy (Phase 2 target).
- **Preserved domain logic:** Do not remove or alter membership model, token ability format, personal tenant concept without ADR amendment.
- **Deferred:** Identification method selection documented; implementation in Phase 2.

---

## 7. References

- `.cursor/reports/PHASE1_5_TENANCY_TRANSITION_ROADMAP.md` — Phase 1.5 bridge plan, evidence, ADR closure spec, Phase 2 Identification Decision
- docs/architecture/ADR/README.md
- .cursor/memory/PROJECT_MANIFEST.md
- docs/api-conventions.md — tenant:{uuid} ability

---

## Repo State Stamp

| Field | Value |
|-------|-------|
| Git commit | `9d12fc50cacfd7e11750a96143814c72b705b2f0` |
| Date/time | 2026-02-26 01:03 +03 (Asia/Riyadh) |
| Command | `git rev-parse HEAD` |
| Note | stancl dependency introduced; not activated. Stancl introduced: v3.9.1; config published; middleware unchanged. |
