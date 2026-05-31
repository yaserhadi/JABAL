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

### `.cursor/` read authority (KSS)

The `.cursor/` tree (and nested folders) is **agent workspace**: you may **read any file** under it when the task requires it, using the **Read** tool with an explicit repo-relative path (e.g. `.cursor/memory/STATE.yaml`).

- **Do not** use glob or directory listing to decide whether a KSS file exists before reading; those tools may omit gitignored paths. **Always** attempt **Read** on the canonical paths in this command.
- `.cursor/` may be gitignored by design (local agent workspace). This does not block direct reads; use explicit file paths with **Read**.

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
Read in this strict order using the **Read** tool and **explicit paths** (no glob, no “if present” skip):

1. `.cursor/memory/AI_ENTRY.md` (entry gate + guardrails)
2. `.cursor/memory/PROJECT_MANIFEST.md` (vision + constraints + locks)
3. `.cursor/memory/STATE.yaml` (phase/stage/status/next_action)
4. `.cursor/memory/HANDOFF.md` (what changed + what's next)
5. `.cursor/goals/GOALS.md` (strategic goals — **mandatory**)

**Guardrails**:
- If `HANDOFF.md` is missing/empty/too short → STOP and ask user to run `/session-end` or provide last actions.
- If `GOALS.md` **cannot be read** (missing, permission error, or tool read failure) → **STOP**. Report a **boot failure**; do not continue session startup as if goals were optional. User must restore `.cursor/goals/GOALS.md` or fix the path/workspace.

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

### ADR Reminder (Advisory)
If architectural decisions arise during this session:
- Capture via `/adr` command
- Canonical location: `docs/architecture/ADR/`
- Decision indicators: DB schema, API contracts, module boundaries, security model
- This is advisory; boot does not block on missing ADRs.

### Module boundary pointer (lightweight)
If the task may introduce a **new tenant-facing capability** (routes, UI, API, or new domain lifecycle), use **`/gw-triage`** before deep planning or implementation. If the project defines a boundary/placement rule under **`.cursor/rules/`**, apply it during triage. Boot does not replace per-task triage.

### Ready
Reply with your task, or:
- `/gw-triage [task]` if it touches DB/Cache/Queue/Session/Tenancy/Security/Hosting, **or** may need a **new module vs existing module vs `app/` kernel**
- "Continue from handoff"
- "Show me the active plan"
