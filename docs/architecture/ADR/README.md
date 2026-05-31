# Architecture Decision Records (ADR)

Human deliberation records for architectural decisions. One file per decision.

## Index (Jabal)

| ADR | Title | Status |
|-----|--------|--------|
| [ADR-0001](ADR-0001-tenancy.md) | Multi-tenancy approach (stancl) | Final |
| [ADR-0002](ADR-0002-tenant-rbac-provisioning.md) | Tenant RBAC provisioning (catalog + runtime + shell nav) | Final |
| [ADR-0003](ADR-0003-modular-monolith-module-boundaries.md) | Modular monolith — module boundaries (documented hybrid) | Final |
| ADR-0004 | Billing plans and entitlements (legacy Phase 4A) | Draft — on experimental branch only (not in repo) |
| ADR-0005 | Enterprise access — OIDC and MFA (legacy Phase 4B) | Draft — on experimental branch only (not in repo) |
| [ADR-0006](ADR-0006-mfa-architecture-security-model.md) | MFA architecture and security model (legacy Phase 4B addendum) | Reference |
| [ADR-0007](ADR-0007-platform-tenant-application-separation.md) | Platform Management and Tenant Application separation (§3.1.1 runtime boundaries, §3.1.5 Platform RBAC ≠ Tenant RBAC) | Draft |

**Current separation initiative:** [ADR-0007](ADR-0007-platform-tenant-application-separation.md) + [JABAL Core Realignment](../../reports/JABAL_CORE_REALIGNMENT.md). **Stage 2.5** closes runtime/storage isolation (Suite C UAT). ADR-0004–0006 describe **legacy Phase 4** work on experimental branches — **reference / Stage 6+ re-home** only; not merge authority.

## Naming

ADR-NNNN-slug.md (e.g. ADR-0001-tenancy.md). Sequential numbering.

## Status

Draft | Final

## Owner

Owner: KSS Steward - YH

## Authority

ADR does not grant execution authority. Only PROJECT_MANIFEST, INTEGRITY_RULES, STATE, VERSIONS do. If ADR conflicts with enforcement layers, enforcement layers win.

## Ownership & Accountability

- Owner represents the human governance authority responsible for the decision.
- Default Owner: **KSS Steward - YH**
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
Owner: KSS Steward - YH

---

## 1. Context
## 2. Options Considered
## 3. Decision
## 4. Consequences
## 5. Risks & Controls
## 6. Enforcement Impact (For KSS Sync Only)
## 7. References
```
