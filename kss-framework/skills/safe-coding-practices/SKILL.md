---
name: safe-coding-practices
description: Enforce evidence-based development and safe change control. Use for refactors, bugfixes, migrations, schema changes, RBAC, permissions, auth, tenancy, security-sensitive changes, production-impacting work, PR reviews, audits, and any task requiring rollback, auditability, or minimal diffs.
---

# Safe Coding Practices Skill

## Purpose

This skill enforces:

- Evidence-based conclusions (no assumptions)
- Minimal, safe, reviewable diffs
- Modular boundaries and least privilege
- Auditability and traceability
- Clear verification and rollback awareness

## Non-Negotiable Behaviors

- Do not claim files, APIs, or behaviors exist without evidence.
- Prefer the smallest change that solves the problem.
- Never propose destructive or irreversible actions without explicit approval and rollback.
- If critical context is missing (env = prod? scope? constraints?), stop and ask.

## Evidence-Based Workflow (Required)

When diagnosing or planning changes, follow this order:

1. **Evidence**: List what you observed (files/paths/snippets/logs/tests).
2. **Hypothesis**: What you think is happening (and why).
3. **Verification**: How to confirm quickly (commands/tests).
4. **Decision**: Proceed only after evidence supports it.

### Evidence Checklist

- What file(s) and line(s) support the conclusion?
- What command output supports the conclusion?
- What test reproduces the issue?

## Minimal Safe Change Rules

- Keep changes scoped to one concern per commit.
- Avoid mixing refactor + feature + formatting in one change.
- Prefer additive changes over breaking changes.
- If breaking changes are unavoidable:
  - Provide a migration path
  - Document it clearly
  - Add regression tests

## Modular Design and Least Privilege

- Identify the **owning module/component** first.
- Do not cross module boundaries without a contract/interface or event.
- Apply least privilege:
  - Deny by default
  - Explicit permissions
  - Avoid broad admin access

## Output Contract (Always Use)

Every response that proposes code changes MUST follow this structure:

### 1. Scope and Ownership

- Owner module/component:
- Affected areas:

### 2. Plan

- Steps (numbered):
- Risks:
- Rollback strategy (if applicable):

### 3. Files

- Add:
- Edit:
- Remove (if any):

### 4. Diff Summary

- Bullet list of what changes and why (reviewable):

### 5. Verification

- Commands/tests to run:
- Expected results:

### 6. Open Questions

- Only if required; otherwise state "None".

## Templates

### A. Change Summary Template

```
- What changed:
- Why it changed:
- Risk level: (low/medium/high)
- Rollback: (how to revert safely)
- Verification: (tests/commands)
```

### B. Verification Checklist Template

```
Pre-change:
- [ ] Confirm current behavior with reproduction steps
- [ ] Identify affected files and ownership
- [ ] Confirm environment (dev/stage/prod) and constraints

Post-change:
- [ ] Run lint/format if applicable
- [ ] Run unit/feature tests
- [ ] Run targeted reproduction steps
- [ ] Confirm no unintended diff scope

Rollback readiness:
- [ ] Revert plan documented (commit revert / rollback steps)
- [ ] Data/schema changes have a safe backout plan
```

## Stop Conditions (Ask Before Acting)

Stop and ask if:

- Production impact is possible and not explicitly approved.
- Data deletion, bulk updates, or permission changes are involved.
- You cannot confirm repo/environment context.
- The requested task conflicts with existing rules or project architecture.
