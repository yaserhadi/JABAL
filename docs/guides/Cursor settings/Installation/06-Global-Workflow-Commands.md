# 06 — Global Workflow Commands

**Purpose:** Workflow commands for triage, risk check, review, handoff.

## Prefer Separate Commands

Use these (copy from `commands/` to `~/.cursor/commands/`):

| Command | Purpose |
|---------|---------|
| /gw-triage | Triage unclear request |
| /gw-riskcheck | Assess risks, CAB flag |
| /gw-review | Review changes before merge |
| /gw-handoff | Summarize for reviewer |

**Legacy:** `global-workflow.md` provides `/global-workflow triage` (etc.) in one file. The separate gw-* commands are preferred.
