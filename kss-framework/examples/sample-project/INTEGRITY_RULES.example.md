# Integrity Rules (Example)

This is an example of a filled INTEGRITY_RULES.md file. Use the template from `templates/INTEGRITY_RULES.template.md` and fill with your project-specific verification commands.

## Core Rules (Non-negotiable)

1. No ADR files under `.cursor`. ADRs are allowed only in `docs/architecture/ADR/`.
2. No [TBD] in active memory files.
3. DOC_POLICY contains paths only.
4. Human ADRs live in docs/architecture/ADR/. Enforcement layers (MANIFEST, etc.) are authority.
5. MANIFEST contains only constraints and locks, not discussions.
6. HANDOFF is replace-only and must be backed up before overwrite.

---

## Domain Locks Verification

### Lock: API-VERSIONING

**Verification commands:**

Check for unversioned API routes:

```bash
rg "Route::(get|post|put|delete).*['\"]api[^v]" routes/
```

Expected: empty (no unversioned routes)

Check that all API routes have version prefix:

```bash
rg "Route::(get|post|put|delete).*['\"]api/v[0-9]" routes/
```

Expected: matches found (all routes are versioned)

**Stop conditions:**
- If unversioned route found → fix before proceeding

---

### Lock: SECURITY-RBAC

**Verification commands:**

Check for hardcoded role checks:

```bash
rg "role\s*==\s*['\"]admin['\"]" app/
```

Expected: empty (no hardcoded admin checks)

Check that middleware is applied to protected routes:

```bash
rg "middleware\(.*auth" routes/
```

Expected: matches found (auth middleware on protected routes)

**Stop conditions:**
- If hardcoded role check found → refactor to use policy
- If protected route lacks middleware → add auth middleware

---

## Plan Governance Validation

Before executing any plan in `.cursor/plans/`:

```bash
rg -l "## Governance Reference" .cursor/plans/*.plan.md
```

**Validation Rules:**

| Check | Requirement |
|-------|-------------|
| Governance Reference section | MUST exist in active plan |
| Lock reference | MUST point to valid MANIFEST lock |
| STATE.yaml status | If `manual-oversight` → owner approval required |
