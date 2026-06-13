# Phase 4 Re-home — Closure Summary

**Branch:** `feature/core-realignment-foundation`  
**Date:** 2026-05-28  
**LKGS:** `main` @ `4f40f0b` (106 tests) → **114 tests pass** on foundation branch

## Waves completed

| Wave | Deliverable | Status |
|------|-------------|--------|
| 0 | Baseline + governance LKGS | ✓ |
| 0.5 | JABAL, ROADMAP, ADR README, ADR-0007 R11–R15, MANIFEST | ✓ |
| 1 | [PHASE4_REHOME_FOUNDATION.md](PHASE4_REHOME_FOUNDATION.md), F1–F8, platform RBAC, tenant_contacts, memberships | ✓ |
| 1.5 | [PHASE4_SALVAGE_AUDIT.md](../.cursor/reports/PHASE4_SALVAGE_AUDIT.md) | ✓ |
| 2 | `Modules/Billing`, ADR-0004 Draft, entitlements resolver | ✓ (core); **Billing loadability gate passed** |
| 3 | Tenant-layer MFA migration (4B-1 foundation); full MFA UI/salvage follow-up | Partial — schema + charter path |
| 4 | [PHASE4_OBSERVABILITY_CHARTER.md](PHASE4_OBSERVABILITY_CHARTER.md) | ✓ charter |

## Operational notes

**Module autoload (F6 gate):** After adding any nWidart module with `Modules/<Name>/composer.json`, run `composer dump-autoload -o` before tests. See [PHASE4_REHOME_FOUNDATION.md](PHASE4_REHOME_FOUNDATION.md) §7.1.

## Key architectural outcomes

- **F4 Path A:** `platform_roles` / `platform_permissions`; `EnsurePlatformAdmin` uses `platform.access`
- **R11:** Tenant `memberships` authority; central `tenant_users` deprecated (§9.1 foundation report)
- **R12:** `tenant_contacts` + contact role catalog on central
- **R14:** `commercial_owner_contact_id` + `tenant_ownerships`
- **R15:** Commercial identity ≠ application identity (`tenant_contacts` vs tenant `users`)
- **Legacy sunset:** central `tenant_users` + legacy central Spatie RBAC — removal plan in [PHASE4_REHOME_FOUNDATION.md](PHASE4_REHOME_FOUNDATION.md) §9
- **F7/F8:** `ForbiddenArtifactCrossoverTest`, `CrossDatabaseAuthDependencyTest`

## References

- Plan: `.cursor/plans/phase_4_re-home_revised_9d6e261c.plan.md`
- Foundation: [PHASE4_REHOME_FOUNDATION.md](PHASE4_REHOME_FOUNDATION.md)
