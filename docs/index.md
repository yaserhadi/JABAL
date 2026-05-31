# Jabal - Getting Started

Welcome to Jabal! This documentation will help you understand and use the system effectively.

## What is Jabal?

Jabal is a Laravel-based modular monolith application featuring:
- Multi-tenancy support
- Tenant-aware RBAC (legacy Phase 3 on `main`; tenant-layer RBAC under Core Realignment Stage 2+)
- Comprehensive audit logging
- Modular architecture

**Current initiative:** [JABAL Core Realignment](reports/JABAL_CORE_REALIGNMENT.md) — **Stage 2 + 2.5 closed** (platform/tenant runtime separation). See [ADR-0007](architecture/ADR/ADR-0007-platform-tenant-application-separation.md) §3.1.1.

## Governance

AI-assisted development governance for this project:

- **Workspace rules:** `.cursor/rules/` (module gate, plan gates, git branch workflow)
- **AI memory:** `.cursor/memory/` (STATE, MANIFEST, HANDOFF, INTEGRITY_RULES)
- **Human ADRs:** `docs/architecture/ADR/`
- **Engineering conventions:** `.cursor/memory/conventions/`

Portable commands and global rules are installed under `~/.cursor/` from an external governance toolkit (not vendored in this repo).

## Quick Links

- [JABAL Core Realignment](reports/JABAL_CORE_REALIGNMENT.md) - Current initiative (Stages 0–6)
- [Architecture Decisions (ADRs)](architecture/ADR/README.md) - Architecture decision records
- [Roadmap](roadmap/ROADMAP.md) - Product roadmap
- [Reference](reference/README.md) - Feature and contributor reference guides
- [Tenancy `.env` guide](reference/tenancy-environment.md) - `TENANCY_MODE` and related variables

## Documentation Structure

| Location | Folder | Purpose |
|----------|--------|---------|
| `docs/` | `architecture/ADR/` | Architecture Decision Records (Jabal-specific) |
| `docs/` | `roadmap/` | Phase overview, backlog & deferrals (Jabal-specific; strategy stays in `.cursor/goals/GOALS.md`) |
| `docs/` | `reference/` | Feature and contributor reference guides |
| `docs/` | `reports/` | Initiative and closure reports |

AI governance runtime state is stored in `.cursor/memory/`.
