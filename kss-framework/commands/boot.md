---
name: boot
description: Start AI session with correct context loading (paths-only policy). Loads core memory + shows continuation point.
---

# /boot — AI Session Startup (KSS)

**Related Commands**: `/session-end`, `/docpack`, `/gw-triage`

## Purpose
Load project context for a new agent safely:
- DOC_POLICY = paths only (no governance logic)
- MANIFEST = constraints/locks/final architectural choices (short)
- STATE = execution reality (phase/stage/status)
- HANDOFF = last session continuity (next actions, blockers)

---

## Workflow

### 1) Read paths (no modes)
- Read `.cursor/DOC_POLICY.yaml` **for paths only**
- If missing: assume defaults:
  - memory: `.cursor/memory/`
  - goals: `.cursor/goals/`
  - plans: `.cursor/plans/`
  - docs: `docs/`

*Note: `goals` and `plans` are boot-specific; DOC_POLICY does not define them.*

### 2) Load core files (must)
Read in this strict order:
1. `.cursor/memory/AI_ENTRY.md` (entry gate + guardrails)
2. `.cursor/memory/PROJECT_MANIFEST.md` (vision + constraints + locks)
3. `.cursor/memory/STATE.yaml` (phase/stage/status/next_action)
4. `.cursor/memory/HANDOFF.md` (what changed + what's next)
5. `.cursor/goals/GOALS.md` (if present)

**Guardrail**:
- If `HANDOFF.md` is missing/empty/too short → STOP and ask user to run `/session-end` or provide last actions.

### 3) Optional: load active plan pointer (no plan scanning)
- If `.cursor/plans/ACTIVE.plan.md` exists: read it and show "Active Plan: …"
- Do NOT list/archive/search all plans unless user asks.

### 4) On-demand sources (read only when relevant)
Only open when the current task requires it:
- `docs/architecture/ADR/README.md` (architecture decisions: Pending/Final)
- `.cursor/memory/VERSIONS.md` (version facts, migrations)
- `.cursor/memory/INTEGRITY_RULES.md` (drift checks / hardening rules) — if exists

---

## Output Format (must)

## Session Loaded
**Project:** [from MANIFEST identity]
**Execution State:** [from STATE: phase / stage / status]
**Active Plan:** [from ACTIVE.plan.md if present; else "none"]
**Key Constraints:** [top 3 from MANIFEST]

### Where We Are (STATE)
- Phase: ...
- Stage: ...
- Status: ...
- Next action: ...

### Previous Session (HANDOFF)
- Summary: ...
- Blockers: ...
- Next tasks: ...

### Ready
Reply with your task, or:
- `/gw-triage [task]` if it touches DB/Cache/Queue/Session/Tenancy/Security/Hosting
- "Continue from handoff"
- "Show me the active plan"
