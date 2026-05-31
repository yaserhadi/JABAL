# ADR-0006: MFA architecture and security model (Phase 4B addendum)

Status: **Reference** (superseded for merge/execution by JABAL Core Realignment)  
Date: 2026-03-31  
Owner: YH

**Supersession:** Legacy Phase 4B / `feature/mfa-hardening`. **Do not merge** MFA assumptions (central `users`, pre-separation identity) to `main`. Re-home MFA on `TenantUser` / ADR-0007 at **Core Realignment Stage 6+** only. Canonical track: [JABAL_CORE_REALIGNMENT.md](../../reports/JABAL_CORE_REALIGNMENT.md).

**Phase 4B addendum** on `feature/mfa-hardening`. Implements production hardening atop ADR-0005. Evidence: `docs/reports/PHASE4B_CLOSURE_REPORT.md` (addendum section, if present on branch).

---

## 1. Context

JABAL’s MFA is an **Identity-owned security capability**, not a separate module and not “the google2fa package.”

| Layer | Owner |
|-------|--------|
| **MFA system** | `Modules/Identity` — `MfaService`, models, middleware, routes, UI |
| **TOTP engine** | `pragmarx/google2fa-laravel` / `Google2FA` — cryptographic primitive only |

Aligns with ADR-0003 (module boundaries) and ADR-0005 (enterprise access). Billing entitlements gate `mfa_available` / plan-level `mfa_required`; tenant policy in `tenant_security_policies`.

---

## 2. Ownership

| Asset | Location |
|-------|----------|
| `user_mfa`, `user_mfa_recovery_codes` | `Modules/Identity/database/migrations/` |
| `tenant_security_policies` | Identity migrations |
| `MfaService`, `EnsureMfaVerified` | `Modules/Identity` |
| Rate limiter registration | `app/Providers/AppServiceProvider` (kernel glue) |
| MFA Inertia pages | `resources/js/Pages/Security/` |

**Not in `app/`:** feature controllers, MFA business rules, or storage.

---

## 3. Lifecycle

```text
enroll (beginEnrollment) → confirm (TOTP + recovery codes issued)
    → tenant access: challenge when mfa_required
    → mfa_verified_at in session (TTL default 30 minutes)
    → logout / platform reset clears MFA state
```

---

## 4. Recovery model

- Recovery codes: **bcrypt hashes** in `user_mfa_recovery_codes`; plain codes shown **once** at confirm.
- Single-use via `used_at`.
- Recovery attempts use a **separate** rate-limit bucket from TOTP challenge.

---

## 5. Tenant policy

- Plan features: `limits.features.mfa_available`, `limits.features.mfa_required` (Billing).
- Tenant override: `tenant_security_policies.mfa_required` (Identity).
- `MfaService::isMfaRequired()` combines both when entitled.

---

## 6. Abuse resistance

Config: `identity.security.mfa` in [`Modules/Identity/config/security.php`](../../Modules/Identity/config/security.php).

| Bucket | Limiter name | Purpose |
|--------|----------------|---------|
| TOTP / enroll confirm | `security-mfa-challenge` | Brute-force 6-digit codes |
| Recovery codes | `security-mfa-recovery` | Recovery brute-force |

On lockout: HTTP **429**, code `mfa_locked_out` (no user enumeration).

Failed attempts audited as `user_mfa.challenge_failed`; recovery success as `user_mfa.recovery_used`.

---

## 7. Session model

- After successful **confirm** or **challenge**: `$request->session()->regenerate()` then set `mfa_verified_at`.
- Mitigates session fixation (ADR-0005 §15).
- **Trusted devices:** deferred.

---

## 8. Step-up authentication

**Actor-scoped verification (not user-global):**

- Session: `mfa_verified_at` in the active web session (implicitly scoped by session id).
- API / Sanctum: cache key `mfa_verified_at:{userId}:{tokenId}` (TTL = `verification_ttl_minutes`, default **30**, env `SECURITY_MFA_VERIFICATION_TTL`).
- `MfaVerificationContext` resolves the actor from the **Bearer token** (preferred) or session; `currentAccessToken()` alone is not used when a Bearer token is present.
- `clearVerification()` clears the **current actor only**; `resetForUser()` / MFA disable clear **all** actor keys for that user.

**Contract:**

- `MfaService::hasRecentVerification()` / `assertRecentVerification()` — abort **403**, code `mfa_step_up_required`.
- Step-up entry: `POST /api/v1/tenants/current/security/mfa/step-up`, `POST /api/v1/me/mfa/step-up` (platform).

**Wired sensitive actions:**

| Action | Route / entry |
|--------|----------------|
| SSO security-sensitive mutation | `PUT /api/v1/tenants/current/security/sso` (`TenantSsoService::requiresStepUp`) |
| MFA reset (platform) | `POST /api/v1/admin/users/{user}/mfa/reset` |
| MFA disable (self) | `DELETE /api/v1/tenants/current/security/mfa` |
| Recovery regen | `POST .../security/mfa/recovery-codes/regenerate` (atomic invalidation of old codes) |
| Password change | `PATCH /api/v1/me/password` (other tokens/sessions revoked; current actor kept) |
| Email change | `PATCH /api/v1/me/email` → `pending_email`; `POST /api/v1/me/email/verify` |
| Billing plan change | `PATCH /api/v1/admin/tenants/{tenant}/subscription/plan` |
| Revoke all sessions | `POST .../security/sessions/revoke-others` |

---

## 9. Reset governance

- Platform break-glass: `POST /api/v1/admin/users/{user}/mfa/reset` via `platform.admin` middleware.
- Audited as `user_mfa.reset`; never returns secrets or recovery codes.
- Clears `user_mfa`, recovery rows, and **all** actor verification keys for the target user.

---

## 10. Future extensibility (out of scope)

- WebAuthn / passkeys
- SMS MFA
- IdP MFA trust
- Trusted devices / remember device
- Sanctum API MFA enroll/challenge gates

---

## 11. References

- ADR-0003, ADR-0005
- [`docs/reports/PHASE4B_CLOSURE_REPORT.md`](../../reports/PHASE4B_CLOSURE_REPORT.md) — addendum section
- Implementation: `Modules/Identity/app/Services/MfaService.php`
