# Architecture Decision Records (ADR)

Human deliberation records for architectural decisions. One file per decision.

## Index (Jabal)

| ADR | Title | Status |
|-----|--------|--------|
| [ADR-0001](ADR-0001-tenancy.md) | Multi-tenancy approach (stancl) | Final |
| [ADR-0002](ADR-0002-tenant-rbac-provisioning.md) | Tenant RBAC provisioning (catalog + runtime + shell nav) | Final |
| [ADR-0003](ADR-0003-modular-monolith-module-boundaries.md) | Modular monolith — module boundaries (documented hybrid) | Final |
| [ADR-0004](ADR-0004-billing-plans-entitlements.md) | Billing plans and entitlements (Phase 4A re-home) | Draft |
| ADR-0005 | Enterprise access — OIDC and MFA (legacy Phase 4B) | Draft — re-home Wave 3; not merge authority |
| [ADR-0006](ADR-0006-mfa-architecture-security-model.md) | MFA architecture and security model (legacy Phase 4B addendum) | Reference — tenant-layer re-home Wave 3 |
| [ADR-0007](ADR-0007-platform-tenant-application-separation.md) | Platform Management and Tenant Application separation (§3.1.1 runtime boundaries, §3.1.5 Platform RBAC ≠ Tenant RBAC, §3.2 R11–R15 contacts/ownership/commercial identity) | Draft |

**Current separation initiative:** [ADR-0007](ADR-0007-platform-tenant-application-separation.md) + [JABAL Core Realignment](../../reports/JABAL_CORE_REALIGNMENT.md) + **Phase 4 re-home (foundation-first)**. ADR-0004–0006 describe **legacy Phase 4 goals** — re-homed after F1–F8 via salvage/cherry-pick; **not merge authority**.

## Naming

ADR-NNNN-slug.md (e.g. ADR-0001-tenancy.md). Sequential numbering.

## Status

Draft | Final

## Owner

Owner: YH

## Authority

ADR does not grant execution authority. Only PROJECT_MANIFEST, INTEGRITY_RULES, STATE, VERSIONS do. If ADR conflicts with enforcement layers, enforcement layers win.

## Ownership & Accountability

- Owner represents the human governance authority responsible for the decision.
- Default Owner: **YH**
- ADR may be generated using AI assistance (e.g., adr-steward), but AI tools do not own architectural decisions.
- If ADR conflicts with enforcement layers, enforcement layers win.

> Optional metadata line allowed:
> Generated-by: adr-steward (extraction tool only, not owner)

Do NOT imply that AI agents own decisions.

**Canonical entry:** Do not create DECISIONS.md. ADR/README is the canonical entry point for architecture decisions.

## Final ADR Sync Checklist (for humans)

If Status = Final, ensure enforceable constraints are mirrored in:

- PROJECT_MANIFEST (architecture locks)
- INTEGRITY_RULES (behavioral gates)
- VERSIONS (baseline/version pins)

## Template

See ADR-0001-tenancy.md for structure, or use:

```markdown
# ADR-XXXX: [Decision Title]

Status: Draft | Final  
Date: YYYY-MM-DD  
Owner: YH

---

## 1. Context
## 2. Options Considered
## 3. Decision
## 4. Consequences
## 5. Risks & Controls
## 6. Enforcement Impact (for governance sync only)
## 7. References
```
