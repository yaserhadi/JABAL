# Module boundaries — Platform Management vs Tenant Application

Status: **Active (Stage 2 + 2.5 delivered — logical split and runtime session isolation)**  
ADR: [ADR-0007](ADR/ADR-0007-platform-tenant-application-separation.md) — §3.1.1–§3.1.5

## Bounded contexts

| Context | Owns | Must not own |
|---------|------|----------------|
| **Platform Management** | `platform_users`, `platform_sessions`, `platform_password_reset_tokens`, platform RBAC (Stage 4+), tenant registry, domains, plans, subscriptions, provisioning, platform audit | Tenant login, tenant `sessions`, workspaces, tenant Spatie RBAC |
| **Tenant Application** | `TenantUser`, tenant `sessions`, tenant RBAC (Spatie on tenant connection), workspaces, tenant settings UI, tenant security (SSO/MFA when re-ported) | Platform billing mutations, tenant registry CRUD, `platform_sessions` |

## Runtime middleware (Stage 2.5)

| Stack | Middleware group | Session store |
|-------|------------------|---------------|
| Platform | `platform.web` + `ConfigureApplicationRuntime:platform` | `central.platform_sessions` |
| Tenant | `tenant.web` + `ConfigureApplicationRuntime:tenant` | tenant `sessions` |

Platform RBAC ≠ Tenant RBAC — no shared Spatie tables or guards (ADR-0007 §3.1.5).

## Target module map (incremental)

| Current module | Target context | Notes |
|----------------|----------------|-------|
| `Modules/Billing` | Platform Management | Central commercial state |
| `Modules/Settings` (platform settings) | Platform Management | `/admin` or `/platform` routes |
| `Modules/Tenancy` (registry, provision) | Platform Management | Tenant path routes stay tenant-facing |
| `Modules/Identity` | **Split** | Platform security APIs → Platform; tenant auth → Tenant Application |
| `Modules/Workspaces` | Tenant Application | Domain tables on tenant store |
| `Modules/Audit` | **Split** | Platform audit vs tenant-scoped audit events |
| `Modules/Api` | **Split** | `/api/v1/admin` → Platform; tenant API → Tenant Application |

## Access rules

- Tenant data only via `TenantStorageResolver` + tenant context + scoped models.
- No tenant business logic in Platform modules.
- No platform administration in Tenant modules.

## Interim state (during migration)

Existing nWidart module names remain until moved; new code must follow the target context column above.
