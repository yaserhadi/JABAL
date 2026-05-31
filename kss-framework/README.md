# KSS Framework

**Knowledge Stewardship System** — A lightweight governance framework for AI-assisted development that ensures persistent memory, clear authority, and controlled execution across agent sessions.

Owner: <KSS_Steward_Name>
Example: KSS Steward - John

---

## What This Framework Is

KSS provides structure for AI agent governance:
- **Templates** for project memory files
- **Commands** for agent operations
- **Rules** for persistent behavior
- **Agents** for specialized tasks
- **Skills** for reusable capabilities

**Framework = structure. Project = content.**

---

## Quick Start

1. Read [docs/handbook.md](docs/handbook.md) to understand KSS concepts
2. Follow [INSTALL.md](INSTALL.md) to set up your environment
3. Copy templates to your project's `.cursor/memory/` and fill with your content

---

## Mandatory vs Optional Components

| Component | Mandatory | Notes |
|-----------|-----------|-------|
| DOC_POLICY.yaml | Yes | Paths only |
| AI_ENTRY.md | Yes | Entry gate |
| PROJECT_MANIFEST.md | Yes | Project fills locks |
| INTEGRITY_RULES.md | Yes | Project fills domain checks |
| STATE.yaml | Yes | Project fills status |
| HANDOFF.md | Yes | Session continuity |
| ACTIVE.plan.md | Yes | Plan pointer |
| LESSONS.md | Optional | |
| conventions/ | Optional | Advisory only |
| runbooks/ | Optional | |

---

## Contents

| Folder | Purpose |
|--------|---------|
| [templates/](templates/) | Memory file templates with placeholders |
| [commands/](commands/) | Cursor commands (copy to `~/.cursor/commands/`) |
| [rules/](rules/) | Cursor rules (copy to `~/.cursor/rules/`) |
| [agents/](agents/) | Subagent definitions (copy to `~/.cursor/agents/`) |
| [skills/](skills/) | Reusable skills (copy to `~/.cursor/skills/`) |
| [docs/](docs/) | Handbook and reference documentation |
| [examples/](examples/) | Sample project files |

---

## Links

- [KSS Handbook](docs/handbook.md) — Conceptual guide
- [Governance workflow (`/gw-*`)](docs/reference/gw-workflow-commands.md) — When to use triage, riskcheck, review, handoff
- [Installation Guide](INSTALL.md) — Step-by-step setup
- [Philosophy](philosophy.md) — Framework vs Project doctrine
- [Templates](templates/) — Memory file templates

---

## KSS Compatibility Contract

A repository is "KSS-compatible" if it has:

**Mandatory:**
- `.cursor/DOC_POLICY.yaml`
- `.cursor/memory/AI_ENTRY.md`
- `.cursor/memory/PROJECT_MANIFEST.md`
- `.cursor/memory/INTEGRITY_RULES.md`
- `.cursor/memory/STATE.yaml`
- `.cursor/memory/HANDOFF.md`

**Optional:**
- `.cursor/memory/conventions/`
- `.cursor/memory/LESSONS.md`
- `.cursor/goals/GOALS.md`
- `.cursor/plans/`
- `.cursor/reports/`
