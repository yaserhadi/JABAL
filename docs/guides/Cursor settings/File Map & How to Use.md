# Cursor Setup Pack — File Map & How to Use

Concise file map. What each file is for and when/how to use it.

---

## 1) Global (per employee machine)

Installed once; applies to all projects.

### A) Subagents

**Path:** `~/.cursor/agents/`

| File | Purpose | Use When |
|------|---------|----------|
| ai-knowledge-steward.md | Maintains .cursor/memory/ | Via /docpack (AI track) |
| user-doc-steward.md | Maintains docs/ (user-facing) | Via /docpack (user track) |
| adr-steward.md | Extracts ADRs from pasted text | Via /adr command |

### B) Commands

**Path:** `~/.cursor/commands/`

| File | Purpose | Use When |
|------|---------|----------|
| boot.md | Load project context | Start of every session |
| session-end.md | Save handoff to HANDOFF.md | Before closing chat |
| docpack.md | Detect → Propose → Apply docs | End of session |
| gw-triage.md | Triage unclear request | Request ambiguous |
| gw-riskcheck.md | Assess risks, CAB flag | Before prod/security changes |
| gw-review.md | Review changes | Before merge |
| gw-handoff.md | Summarize for reviewer | In-session handoff |
| git-prepare.md | Create branch from main | Start new work |
| git-save.md | Commit progress | Safe checkpoint |
| git-finalize.md | Merge and delete branch | After tests pass |
| en.md | English-only reply | Override language |
| ar.md | Arabic-only reply | Override language |
| aren.md | Dual language reply | Override language |

---

## 2) Project (inside each repository)

### A) AI Track (engineering memory)

**Folder:** `.cursor/memory/` (not root `memory/`)

| File | Purpose |
|------|---------|
| .cursor/DOC_POLICY.yaml | Canonical paths only; top of authority |
| .cursor/memory/AI_ENTRY.md | Entry gate, reading order, guardrails |
| .cursor/memory/PROJECT_MANIFEST.md | Vision, constraints, architectural locks |
| .cursor/memory/STATE.yaml | Phase, stage, execution status |
| .cursor/memory/HANDOFF.md | Last session summary, what next |
| .cursor/memory/INTEGRITY_RULES.md | Non-negotiable guardrails |
| .cursor/memory/VERSIONS.md | Version facts |
| .cursor/memory/LESSONS.md | Mistakes to avoid (optional) |

**ADRs:** `docs/architecture/ADR/` — not under .cursor. Use /adr command.

### B) User Track

**Folder:** `docs/`

| Path | Purpose |
|------|---------|
| docs/index.md | User docs entry point |
| docs/guides/ | How-to guides |
| docs/reference/ | Reference material |
| docs/architecture/ADR/ | Architecture decision records |

### C) Project Commands

**Path:** `.cursor/commands/`

| File | Purpose |
|------|---------|
| adr.md | Create ADR from pasted text (project-specific) |

---

## 3) Quick Usage

| Scenario | Action |
|----------|--------|
| Start session | `/boot` |
| End session | `/session-end` (Agent mode) |
| Before risky change | `/gw-riskcheck` |
| Update docs | `/docpack` |
| Create ADR | `/adr` + paste text |
