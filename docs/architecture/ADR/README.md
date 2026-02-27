# Architecture Decision Records (ADR)

Human deliberation records for architectural decisions. One file per decision.

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
