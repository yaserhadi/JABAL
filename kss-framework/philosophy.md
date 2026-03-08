# KSS Philosophy

Owner: <KSS_Steward_Name>
Example: KSS Steward - John

---

## Core Principle

**Framework defines structure. Project defines content.**

The KSS Framework provides:
- File roles and purposes
- Templates with placeholders
- Installation order
- Authority model

The Project provides:
- Actual architectural locks
- Real verification commands
- Execution status
- ADRs and reports

---

## Authority Model

```
DOC_POLICY > PROJECT_MANIFEST > INTEGRITY_RULES > ADR > STATE > HANDOFF > GOALS
```

| Layer | Authority | Content |
|-------|-----------|---------|
| DOC_POLICY | Highest | Canonical paths only |
| PROJECT_MANIFEST | High | Architectural locks and constraints |
| INTEGRITY_RULES | High | Verification gates and guardrails |
| ADR | Advisory | Human deliberation records (non-authoritative) |
| STATE | Execution | Current phase, status, blockers |
| HANDOFF | Session | Next actions, context for continuity |
| GOALS | Strategic | Long-term objectives |

---

## Templates Are Scaffolds, Not Policies

Templates in this framework:
- Contain placeholders (`<project_name>`, `<LOCK_ID>`, etc.)
- Show structure and expected sections
- Do NOT enforce specific content
- Do NOT contain real project data

When you adopt KSS:
1. Copy templates to your project
2. Replace placeholders with your content
3. Add your locks, rules, and verification commands

The framework says "there must be a PROJECT_MANIFEST."
The project decides what goes inside it.

---

## Enforcement Lives in Project Memory

The framework does NOT enforce:
- Specific lock IDs
- Specific verification commands
- Specific version pins
- Specific phase/status values

Enforcement happens in the project's:
- `.cursor/memory/PROJECT_MANIFEST.md` — Architectural locks
- `.cursor/memory/INTEGRITY_RULES.md` — Verification gates
- `.cursor/memory/VERSIONS.md` — Version pins
- `.cursor/memory/STATE.yaml` — Execution status

---

## Separation of Concerns

| Layer | Contains | Must NOT Contain |
|-------|----------|------------------|
| Framework | Structure, templates, tooling | Real project data, locks, STATE |
| Project | Locks, ADRs, STATE, versions | Framework templates |

This separation ensures:
- Framework is portable across projects
- Projects are independent of framework updates
- No contamination between layers

---

## Why This Matters

Without clear separation:
- Framework changes break projects
- Project content leaks into framework
- Portability is lost
- Governance becomes fragile

With clear separation:
- Framework evolves independently
- Projects maintain their own governance
- Any project can adopt KSS by copying and filling templates
- Framework updates don't require project changes
