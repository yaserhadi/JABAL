# Phase 4C — Observability Charter (Wave 4)

**Status:** Charter only — no module implementation until 4B closure  
**Date:** 2026-05-28  
**Gate:** MODULE-CREATION-GATE required before `Modules/Observability`

## Purpose

Define scope for legacy Phase 4C (observability, DR/BCP, performance) re-home on ADR-0007 architecture after enterprise access (4B) completes.

## In scope (future module)

| Capability | Owner | Storage |
|------------|-------|---------|
| Tenant-aware structured logging | Platform + Tenant | Central platform logs; tenant app logs on tenant layer |
| Health/readiness endpoints per runtime | Kernel `app/` | N/A |
| Metrics export (request latency, queue depth) | Platform ops | Central |
| Backup/restore runbooks per `TENANCY_MODE` | Platform | Documented — Stage 5+ for DB-per-tenant |
| Performance baselines (Suites A–F regression) | QA | `docs/reports/` |

## Out of scope (this charter)

- Implementation migrations or UI
- APM vendor selection
- Production DR execution

## Dependencies

- F1–F8 green (Wave 1) ✓
- 4A Billing merged (Wave 2)
- 4B enterprise closure report (Wave 3)

## Next step

Owner approves MODULE-CREATION-GATE → plan `Modules/Observability` or kernel-only metrics first.

## References

- Legacy roadmap Phase 4C in [ROADMAP.md](../roadmap/ROADMAP.md)
- [PHASE4_REHOME_FOUNDATION.md](PHASE4_REHOME_FOUNDATION.md)
