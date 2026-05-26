# Module boundaries — Platform Management vs Tenant Application

Status: **Active (planning)**  
ADR: [ADR-0007](ADR/ADR-0007-platform-tenant-application-separation.md)

## Bounded contexts

| Context | Owns | Must not own |
|---------|------|----------------|
| **Platform Management** | `platform_users`, tenant registry, domains, plans, subscriptions, provisioning, platform audit | Tenant login, workspaces, tenant RBAC for end users |
| **Tenant Application** | `TenantUser`, tenant sessions, workspaces, tenant settings UI, tenant security (SSO/MFA when re-ported) | Platform billing mutations, tenant registry CRUD |

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
