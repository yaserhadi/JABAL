---
name: Create Safe Coding Skill
overview: Create an enhanced "safe-coding-practices" skill with enforced output contracts, stop conditions, and strong trigger terms. Incorporates feedback to make the skill actionable rather than just guidelines.
todos:
  - id: create-skill-dir
    content: Create skill directory at ~/.cursor/skills/safe-coding-practices/
    status: completed
  - id: write-skill-md
    content: Write enhanced SKILL.md with output contract, stop conditions, evidence workflow, and templates
    status: completed
  - id: verify-skill
    content: Verify skill follows authoring best practices (under 500 lines, valid frontmatter, trigger terms)
    status: completed
isProject: false
---

# Create Safe Coding Practices Skill (Enhanced)

## Skill Purpose

This skill enforces:

- Evidence-based conclusions (no assumptions)
- Minimal, safe, reviewable diffs
- Modular boundaries and least privilege
- Auditability and traceability
- Clear verification and rollback awareness

## File Structure

```
~/.cursor/skills/safe-coding-practices/
└── SKILL.md
```

## SKILL.md Content Design

### Frontmatter

```yaml
---
name: safe-coding-practices
description: >
  Enforce evidence-based development and safe change control. Use for refactors, 
  bugfixes, migrations, schema changes, RBAC, permissions, auth, tenancy, 
  security-sensitive changes, production-impacting work, PR reviews, audits, 
  and any task requiring rollback, auditability, or minimal diffs.
---
```

**Note**: The `triggers:` field suggested by ChatGPT is NOT part of Cursor's official skill schema. Cursor only recognizes `name` and `description` in frontmatter. All trigger keywords must be embedded in the description instead.

### Key Sections

1. **Non-Negotiable Behaviors** - Hard rules the agent must follow
2. **Evidence-Based Workflow** - Required order: Evidence -> Hypothesis -> Verification -> Decision
3. **Evidence Checklist** - What supports conclusions (files, commands, tests)
4. **Minimal Safe Change Rules** - Scoped commits, additive over breaking, migration paths
5. **Modular Design and Least Privilege** - Module ownership, deny-by-default
6. **Output Contract (Enforced)** - Mandatory structure for all code change responses
7. **Stop Conditions** - When to pause and ask before acting
8. **Templates** - Change summary and verification checklist formats

### Output Contract Structure (Mandatory)

Every response proposing code changes MUST include:

1. **Scope and Ownership** - Owner module, affected areas
2. **Plan** - Numbered steps, risks, rollback strategy
3. **Files** - Add/Edit/Remove lists
4. **Diff Summary** - Bullet list of what changes and why
5. **Verification** - Commands/tests and expected results
6. **Open Questions** - Only if required

### Stop Conditions

Agent must stop and ask if:

- Production impact possible and not explicitly approved
- Data deletion, bulk updates, or permission changes involved
- Cannot confirm repo/environment context
- Task conflicts with existing rules or architecture

## Implementation Steps

1. Create the skill directory at `~/.cursor/skills/safe-coding-practices/`
2. Write `SKILL.md` with enhanced content including:

   - Strong trigger keywords in description
   - Non-negotiable behaviors
   - Evidence-based workflow with checklist
   - Enforced output contract
   - Stop conditions
   - Ready-to-use templates

3. Verify the file is under 500 lines and follows skill authoring best practices

## Verification

After creation, confirm:

- [ ] Frontmatter has valid `name` and `description` (no unsupported fields like `triggers:`)
- [ ] Description includes trigger keywords (refactor, bugfix, migration, RBAC, tenancy, audit, production, rollback, PR review, security)
- [ ] Output contract is enforced (not optional guidelines)
- [ ] Stop conditions are explicit
- [ ] Templates are actionable and concrete
- [ ] Content is concise (under 500 lines)