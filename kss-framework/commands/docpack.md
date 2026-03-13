---
name: docpack
description: Update documentation after work is done. Follows Detect-Propose-Apply workflow.
---

# Docpack - Documentation Packaging

Update documentation after work is done using a safe, confirmable workflow.

## Purpose
Capture session changes in documentation (.cursor/memory/ and docs/).

## Workflow: Detect → Propose → Apply (NOT automatic)

This command follows a three-phase workflow to ensure safe, confirmable documentation updates.

## Workflow

### 1. Read Paths
Read `.cursor/DOC_POLICY.yaml` for paths only (memory, reports, user_docs, architecture).
- If missing: use defaults (.cursor/memory/, docs/, docs/architecture/). Proceed with workflow.

### 2. Detect
Extract signals from the session:
- What files were modified?
- What decisions were made?
- Was there user impact?
- Were there lessons learned?

**Documentation gap check** (when relevant change types detected):

| Change type | Check for stale or missing docs |
|-------------|---------------------------------|
| DB schema, migrations, migration layout | `.cursor/memory/conventions/database-conventions.md` |
| API, routes, auth flow | `.cursor/memory/conventions/api-conventions.md` |
| RBAC, permissions, roles | `.cursor/memory/conventions/rbac-conventions.md` |
| Test helpers, test patterns | `tests/README.md` |

### 2.5 ADR Completeness Check (Advisory)

Scan session context for decision indicators:

**Triggers** (any of these suggests an architectural decision):
- Module boundary created or changed
- API contract modified (routes, DTOs, auth flow, versioning)
- Tenancy / DB schema / migrations changed
- Security model / RBAC / permissions changed
- Language in commits/discussion: "decided", "chose", "alternative", "trade-off", "breaking change"

**Check:**
- If triggers detected AND no new/updated ADR found in session:
  - Output **ADR Missing Warning** (advisory, not blocking)

**Output Format:**
```
**ADR Completeness Check (Advisory)**:
- Decision indicators detected: [list what triggered]
- ADR coverage found: [ADR-XXXX] / None
- Status: OK / Warning

**If Warning**:
> Recommended: Run `/adr` to capture the decision context.
> If ADR not needed, document rationale: "ADR Not Needed: <reason>"
```

**Important:** This check is advisory only. It does not block docpack execution.

### 3. Propose
Present update plan:

```
DOCPACK PROPOSAL

**ADR Completeness Check**: [OK / Warning - see above]

AI Track Updates (.cursor/memory/):
- [ ] HANDOFF.md - Session summary [required]
- [ ] STATE.yaml - [specific field changes]
- [ ] LESSONS.md - [if applicable]
- [ ] VERSIONS.md - [if breaking change]

Conventions (.cursor/memory/conventions/):
- [ ] database-conventions.md - [if schema, migrations, or migration layout changed]
- [ ] api-conventions.md - [if API contract or auth flow changed]
- [ ] rbac-conventions.md - [if RBAC, permissions, or roles changed]

Project Docs:
- [ ] tests/README.md - [if test helpers or test patterns changed]

Architecture:
- [ ] docs/architecture/ADR/ - [if architecture decision, run /adr]

User Track Updates (docs/):
- [ ] docs/reference/[name].md - [if user impact detected]

Confirm? [Y/n] or specify which to apply
```

### 4. Apply
Only after confirmation:
- Invoke AI Knowledge Steward for .cursor/memory/ updates
- If user impact confirmed, invoke User Documentation Steward for docs/ updates
- Output summary of what was updated

## Enforcement Defaults

| Target | When |
|--------|------|
| HANDOFF | Required |
| STATE | When meaningful |
| LESSONS | Encouraged |
| Conventions | Schema, API, or RBAC changes |
| Project docs (tests/README) | Test helpers or patterns changed |
| ADR | Major architecture decisions |
| User docs | When features change |

## Safe Defaults (auto-apply without confirmation)

- HANDOFF.md update
- STATE.yaml last_updated field

## Require Confirmation

- LESSONS.md changes
- Conventions updates (.cursor/memory/conventions/)
- Project docs updates (tests/README.md)
- Updates to docs/architecture/ADR/
- User documentation updates (docs/)
- VERSIONS.md changes

## Example Usage

```
/docpack
```

The command will analyze the session, detect changes, propose updates, and apply only after confirmation.

## When to Use

Use `/docpack` at the end of any meaningful work session:
- After completing a feature
- After fixing a bug with lessons learned
- After making architectural decisions
- After resolving operational issues
- Before ending a development session

## Integration with Stewards

This command orchestrates the two stewards:
- **AI Knowledge Steward** - Updates .cursor/memory/ (technical, operational)
- **User Documentation Steward** - Updates docs/ (simple, end-user focused)

Each steward writes only to their assigned track.

**Fallback:** If stewards cannot be invoked (missing or misconfigured), apply updates directly to the proposed targets and report what was updated. Do not fail silently.
