# Product Roadmap

High-level direction for Jabal (SaaS Core Platform). Phase-level details and milestones are tracked in project state artifacts and closure reports under `.cursor/reports/`.

## Overview

- **Phase 1:** Technical foundation, modular boundaries, API gate, UI migration (Inertia/Vue/Vuetify).
- **Phase 2:** Stancl tenancy, dual-database architecture, identification and security locks (closed).
- **Phase 3:** Domain layer + RBAC + operational hardening, split into:
  - **3A** — Domain Foundation (first domain tables in `jabal_tenant_shared`, BelongsToTenant, cross-tenant isolation tests).
  - **3B** — RBAC & Authorization (tenant-aware Spatie permissions, governance decisions: Global Admin, cross-tenant reporting, multi-tenant membership).
  - **3C** — Tenant Settings + Operational Hardening (tenant-level settings, user preferences, Queue/Cache/Files tenancy, observability).
- **Phase 4:** Enterprise & scale readiness, split into:
  - **4A** — Subscription/Billing + Plans (plans, usage limits, billing lifecycle).
  - **4B** — Enterprise Access (SSO Azure AD/SAML/OIDC, MFA, rate limiting, device/session controls).
  - **4C** — Observability + DR/BCP + Performance (tenant-aware logging, monitoring, backup/restore, indexing, caching).
- **Phase 5:** Platform productization & tenant evolution (after Phase 4), split into:
  - **5A** — Productization Layer (platform core vs product specifics, module boundaries, lifecycle unification, starter modules, templates).
  - **5B** — Multi-Product / Multi-App Readiness (namespace/app context, feature segregation, module enablement per tenant/product, shared identity, unified portal).
  - **5C** — Advanced Isolation Strategy (`isolation_level` activation: shared/schema/database, tenant migration path, enterprise promotion, data mobility, per-tenant backup).

## Reference

- Agent-facing goals: `.cursor/goals/GOALS.md`
- Project state: `.cursor/memory/STATE.yaml`
- Reports: `.cursor/reports/`
