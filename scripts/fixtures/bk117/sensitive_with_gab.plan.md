---
name: BK-117 fixture sensitive with GAB
overview: Fixture for check-governance-preflight — sensitive with minimum GAB evidence.
governance_sensitive: true
last_updated: "2026-09-04T21:00:00Z"
---

# Fixture

Sensitive tenancy auth migration work (fixture).

## Governance Applicability

- BK / task: BK-117 fixture
- Domains / modules / planes: Tenant plane / Settings module (fixture)
- Auth/security impact: N/A — fixture only; no live auth change
- Persistence impact: N/A — no schema change in this fixture
- Matched MANIFEST locks: N/A — fixture cites none
- Matched Active DECs: `.cursor/memory/decisions/DEC-0026-agent-governance-enforcement.md`
- Matched INTEGRITY: Governance Applicability verification (DEC-0026 / BK-117)
- Matched rules / workflow rules: plan-preflight-gate, governance-applicability
- Explicit N/A: MANIFEST N/A with reason above
- Conflict classification: NO CONFLICT
- Required verification: `scripts/check-governance-preflight.ps1` (enforce)

## References

- Lock: N/A fixture
- Verification: INTEGRITY Governance Applicability verification
- Evidence/Report: N/A fixture
