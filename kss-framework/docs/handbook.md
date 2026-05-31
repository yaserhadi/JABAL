# KSS User Handbook

## Executive Summary

**Knowledge Stewardship System (KSS)** is a lightweight governance framework for AI-assisted development. It gives AI agents persistent memory across sessions and humans governance without heavy process.

### What KSS Provides

- **Session continuity** — HANDOFF captures what happened and what to do next
- **Clear authority** — DOC_POLICY, MANIFEST, STATE, HANDOFF, and ADRs in defined order
- **Execution gate** — Work runs only when `STATE.execution.status == active`
- **Governance without ceremony** — CAB, safety, and Git rules apply automatically

### Who This Handbook Is For

Colleagues adopting KSS: developers, architects, and anyone using Cursor with AI agents on KSS-enabled projects.

---

## Quick Start

### 1. Install Global Rules

Add the 7 organizational rules via **Cursor Settings > Rules for AI** (see Global User Rules in the installation guide).

### 2. Start Every Session

```
/boot
```

This loads project context, execution state, and last session handoff.

### 3. End Every Session

```
/session-end
```

This writes `.cursor/memory/HANDOFF.md` before you close the chat. **Run this in Agent mode** (Plan and Ask cannot write HANDOFF).

---

## Benefits

| Benefit | Description |
|---------|-------------|
| **Session continuity** | HANDOFF tells the next agent (or you) what to do next and what to avoid |
| **Governance without ceremony** | CAB, safety, and Git rules are enforced automatically via user rules |
| **Clear authority** | MANIFEST vs ADR vs STATE is unambiguous; conflicts resolve in a fixed order |
| **Safe execution** | Execution gate blocks work when `status != active` |
| **Auditability** | Reports in one place, ADRs as deliberation records, evidence-based conclusions |
| **Team consistency** | Same rules and commands for all team members |

---

## File Inventory

### Project .cursor Structure

| File | Purpose | Path |
|------|---------|------|
| DOC_POLICY.yaml | Canonical paths only | `.cursor/DOC_POLICY.yaml` |
| AI_ENTRY.md | Entry gate, reading order, execution rules | `.cursor/memory/AI_ENTRY.md` |
| PROJECT_MANIFEST.md | Vision, constraints, architectural locks | `.cursor/memory/PROJECT_MANIFEST.md` |
| STATE.yaml | Phase, stage, execution status | `.cursor/memory/STATE.yaml` |
| HANDOFF.md | Session continuity, next actions | `.cursor/memory/HANDOFF.md` |
| INTEGRITY_RULES.md | Non-negotiable guardrails | `.cursor/memory/INTEGRITY_RULES.md` |
| VERSIONS.md | Version facts | `.cursor/memory/VERSIONS.md` |
| LESSONS.md | Mistakes to avoid (optional) | `.cursor/memory/LESSONS.md` |
| conventions/ | Engineering standards (optional) | `.cursor/memory/conventions/` |
| GOALS.md | Strategic goals only | `.cursor/goals/GOALS.md` |

### Global Commands (User-Level)

Copy to `~/.cursor/commands/`.

| Command | Purpose |
|---------|---------|
| boot | Load project context at session start |
| session-end | Persist handoff to HANDOFF.md |
| docpack | Detect → Propose → Apply doc updates |
| gw-triage | Triage request, propose safest plan; detect architectural decisions |
| gw-riskcheck | Risk assessment, CAB review flag; includes ADR check |
| gw-review | Review diff for safety/compliance; catch unrecorded decisions |
| gw-handoff | Summarize changes for reviewer |
| git-prepare | Create branch from main |
| git-save | Commit progress (no push) |
| git-finalize | Merge branch, delete after |
| en | Respond in English only |
| ar | Respond in Arabic only |
| aren | Respond in both Arabic and English |

### Global Rules (File-Based)

Copy to `~/.cursor/rules/`.

| Rule | Purpose |
|------|---------|
| plans-location.mdc | Plans in project `.cursor/plans/` |
| reports-location-global.md | Reports in `.cursor/reports/` |
| git-main-merge-only.mdc | No edits on `main`/`master`; branch first |

### Subagents

Copy to `~/.cursor/agents/`.

| Agent | Purpose |
|-------|---------|
| adr-steward | Extract and author ADRs from pasted text (via /adr) |
| ai-knowledge-steward | Maintain .cursor/memory/ (via /docpack) |
| user-doc-steward | Maintain user docs in docs/ (via /docpack) |

> Subagents are execution tools. They never own authority. All decisions remain with human governance.

### Skills (Optional)

| Skill | Path | Purpose |
|-------|------|---------|
| safe-coding-practices | `~/.cursor/skills/safe-coding-practices/SKILL.md` | Evidence-based dev, rollback |
| create-rule | `~/.cursor/skills/create-rule/SKILL.md` | Create Cursor rules |
| create-skill | `~/.cursor/skills/create-skill/SKILL.md` | Create Agent Skills |
| update-cursor-settings | `~/.cursor/skills/update-cursor-settings/SKILL.md` | Modify settings.json |

---

## Rules

### 7 Cursor Settings Rules (Global)

Add via **Cursor Settings > Rules for AI**. These apply to all projects:

1. **CAB Governance** — Production/security/data changes need CAB review
2. **Agent Investigation & Git Safety** — Git SOP before claiming missing files; no destructive ops without approval
3. **Global Agent Safety & Integrity** — No bypassing auth, no secrets, correctness over speed
4. **Audit, Risk, Compliance** — ISO 27001/27002 intent, least privilege, auditable
5. **Data Protection, PII** — Treat data as protected, no real secrets in code
6. **Production Environment Safety** — No destructive ops in prod without approval and rollback
7. **Git Change Management & Branching** — All work on branches, no direct main commits

### 3 File-Based Rules (Global)

Copy to `~/.cursor/rules/`:

| Rule | Purpose |
|------|---------|
| plans-location.mdc | Plans stored in project `.cursor/plans/` only |
| reports-location-global.md | Reports stored in `.cursor/reports/` |
| git-main-merge-only.mdc | No edits on `main`/`master`; branch first |

**Response language:** `/en`, `/ar`, `/aren` commands — not a global rule file.

---

## Commands

### Session Commands

| Command | When to Use |
|---------|-------------|
| `/boot` | Start of every session; loads project context |
| `/session-end` | Before closing chat; writes HANDOFF (use Agent mode) |
| `/docpack` | End of session; updates docs via Detect → Propose → Apply |

### Workflow Commands

Governance workflow (`/gw-*`): see **[Governance workflow commands](reference/gw-workflow-commands.md)** for typical order, when to use each command, and quick prompts.

| Command | When to Use |
|---------|-------------|
| `/gw-triage` | Request is unclear; triage and propose safest plan; detect architectural decisions |
| `/gw-riskcheck` | Before production/security/data changes; includes ADR check |
| `/gw-review` | Review changes before merge; catch unrecorded decisions |
| `/gw-handoff` | In-session summary for reviewer |

### Git Commands

| Command | When to Use |
|---------|-------------|
| `/git-prepare` | Start new work; create branch from main |
| `/git-save` | Checkpoint progress; commit without push |
| `/git-finalize` | Merge branch after tests and /session-end |

### Language Commands

| Command | When to Use |
|---------|-------------|
| `/en` | Reply in English only |
| `/ar` | Reply in Arabic only |
| `/aren` | Reply in both Arabic and English |

### Project Command

| Command | When to Use |
|---------|-------------|
| `/adr` | Create ADR from pasted text (ChatGPT, meeting notes, etc.) |

---

## Subagents

| Agent | Invoked By | Purpose |
|-------|------------|---------|
| **adr-steward** | `/adr` command | Extract context, options, decision from pasted text; write ADR to `docs/architecture/ADR/` |
| **ai-knowledge-steward** | `/docpack` | Update `.cursor/memory/` (HANDOFF, STATE, LESSONS, VERSIONS) |
| **user-doc-steward** | `/docpack` | Update `docs/` (user-facing guides) when user impact detected |

---

## KSS Flow

### Execution Flow

```
Session Start → /boot → Read DOC_POLICY, AI_ENTRY, MANIFEST, STATE, HANDOFF, GOALS
                              ↓
                    execution.status == active?
                     /              \
                   No                Yes
                    |                 |
            Do NOT execute    Read HANDOFF next actions
            Clarify only      Work within MANIFEST
                    |                 |
                    └────────┬────────┘
                             ↓
                    /session-end before close
```

### Authority Order

When information conflicts, resolve in this order:

1. DOC_POLICY (paths)
2. PROJECT_MANIFEST (constraints)
3. INTEGRITY_RULES (guardrails)
4. docs/architecture/ADR (human deliberation; non-authoritative)
5. STATE (execution)
6. HANDOFF (continuity)
7. GOALS (strategic)

---

## Ready-to-Use Prompts

| Scenario | Prompt |
|----------|--------|
| Start session | `/boot` then "Continue from handoff" |
| End session | `/session-end` |
| Create ADR from discussion | `/adr` then paste ChatGPT/Cursor/meeting text |
| Assess risk before change | `/gw-riskcheck` then describe the change |
| Review changes before merge | `/gw-review` then paste diff or describe changes |
| Triage unclear request | `/gw-triage` then describe the request |
| Update docs after work | `/docpack` |
| Start work on branch | `/git-prepare` |
| Save progress | `/git-save` |
| Close branch and merge | `/git-finalize` |
| English reply | `/en` then your question |
| Arabic reply | `/ar` then your question |
| Dual language | `/aren` then your question |

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| HANDOFF missing or empty | Run `/session-end` in Agent mode before closing; or provide next actions manually |
| Agent won't execute | Check `STATE.yaml` — execution allowed only when `execution.status == active` |
| Plans saved to wrong folder | Ensure `plans-location.mdc` in `~/.cursor/rules/`; plans go to project `.cursor/plans/` |
| Reports in wrong place | Reports must go to `.cursor/reports/` per reports-location rule |
| /session-end doesn't write | Use **Agent mode**; Plan and Ask modes cannot write HANDOFF |
| Language override ignored | Use `/en`, `/ar`, or `/aren` at start of message |
| ADR conflicts with MANIFEST | Enforcement layers (MANIFEST, INTEGRITY_RULES) win; update them explicitly |

---

## Where to Get Files

See the framework `commands/`, `agents/`, `rules/`, and `skills/` folders for copyable files.
