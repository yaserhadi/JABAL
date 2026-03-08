# Project Manifest (Example)

This is an example of a filled PROJECT_MANIFEST.md file. Use the template from `templates/PROJECT_MANIFEST.template.md` and fill with your project-specific content.

## Project Identity

| Aspect | Value |
|--------|-------|
| Project | Sample Project |
| Vision | Demonstrate KSS lock structure |
| Phase | 1 |

## Audience

- AI agents (primary)
- Human developers (reference)
- Project stakeholders (overview)

## Document Role

This document is the single authoritative source for architectural constraints.
No architectural baseline exists outside PROJECT_MANIFEST.

## Authority Model

DOC_POLICY > PROJECT_MANIFEST > INTEGRITY_RULES > ADR > STATE > HANDOFF > GOALS

## Architectural Locks

### Lock: API-VERSIONING (Applies: Phase 1+)

**Non-negotiables:**
- All API endpoints must include version prefix (`/v1/`, `/v2/`)
- Breaking changes require new major version

**Allowed patterns:**
- `/api/v{N}/resource`

**Forbidden patterns:**
- Unversioned API endpoints (`/api/resource`)

**Stop conditions:**
- If breaking change proposed without version bump → STOP and ask owner

---

### Lock: SECURITY-RBAC (Applies: Phase 1+)

**Non-negotiables:**
- All protected routes must check permissions
- Roles are defined in database, not hardcoded
- Admin access requires explicit role assignment

**Allowed patterns:**
- Middleware-based permission checks
- Policy classes for authorization

**Forbidden patterns:**
- Hardcoded role checks (`if user.role == 'admin'`)
- Bypassing authorization middleware

**Stop conditions:**
- If admin access without role check → STOP and ask owner
