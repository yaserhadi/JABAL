# ADR-0004: Billing Plans and Entitlements

Status: **Draft**  
Date: 2026-05-28  
Owner: YH

**Initiative:** Phase 4 re-home Wave 2 (4A)  
**Foundation:** [PHASE4_REHOME_FOUNDATION.md](../reports/PHASE4_REHOME_FOUNDATION.md), ADR-0007

---

## 1. Context

JABAL requires commercial metadata on `jabal_central` for plans, subscriptions, and entitlements. Tenant application users and memberships remain on the tenant data layer (R11). Billing answers platform-only questions (pay, renew, suspend, seat limits).

---

## 2. Decision

### 2.1 Storage (central only)

| Artifact | Table | Owner |
|----------|-------|-------|
| Plans | `plans` | Platform |
| Subscriptions | `subscriptions` | Platform |
| Entitlements | `entitlements` | Platform |
| Seat limits | `subscriptions.seat_limit` | Platform (metadata) |

### 2.2 Module boundary

- **Module:** `Modules/Billing`
- **Consumers:** Identity and other modules via `App\Support\Contracts\Billing\TenantEntitlementsResolver`
- **Forbidden:** Billing importing tenant `users` / `memberships` for authorization; tenant modules mutating billing tables directly

### 2.3 Entitlement keys (initial)

- `mfa_available`, `mfa_required` — wired in Wave 3 (4B-1b) **after** 4A merged (R5)

---

## 3. Consequences

- Platform operators manage plans/subscriptions via `platform` guard
- Tenant app reads entitlements through contract only
- Legacy `feature/phase-4a-billing-plans` branch is salvage reference, not merge authority

---

## 7. References

- [ADR-0007](ADR-0007-platform-tenant-application-separation.md)
- Phase 4 re-home plan `.cursor/plans/phase_4_re-home_revised_9d6e261c.plan.md`
