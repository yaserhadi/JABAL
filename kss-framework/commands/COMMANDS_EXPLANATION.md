# Commands — Copy Instructions

**Destination:** `~/.cursor/commands/`

| File | Purpose | When to Use |
|------|---------|-------------|
| boot.md | Load project context | Start of every session |
| session-end.md | Save handoff to HANDOFF.md | Before closing chat |
| docpack.md | Update docs after work | End of session, after changes |
| gw-triage.md | Triage unclear request; apply project `.cursor/rules/` boundary rules if present; detect architectural decisions | Request is ambiguous, may involve ADR, or needs placement triage |
| gw-riskcheck.md | Assess risks, CAB flag; includes ADR check | Before production/security changes |
| gw-review.md | Review changes; catch unrecorded decisions | Before merge |
| gw-handoff.md | Summarize for reviewer | In-session handoff |
| git-prepare.md | Create branch from main | Start new work |
| git-save.md | Commit progress | Safe checkpoint |
| git-finalize.md | Merge and delete branch | After tests pass |
| en.md | English-only reply | Override response language for this session |
| ar.md | Arabic-only reply | Override response language for this session |
| aren.md | Dual language (Arabic + English) | Override response language for this session |
| adr.md | Create ADR from text | Architecture decisions |

## Language overrides

Use `/en`, `/ar`, or `/aren` when you need a explicit response language for the rest of the message. These commands replace the retired `agent-response-language-global.md` rule.

| Command | Effect |
|---------|--------|
| `/en` | Agent responds in English only (code and paths unchanged) |
| `/ar` | Agent responds in Arabic only |
| `/aren` | Agent responds in both Arabic and English |

User may ask in any language; the command sets the **response** language only.

## Repo-only (not copied to `~/.cursor/commands/`)

| File | Purpose |
|------|---------|
| COMMANDS_EXPLANATION.md | This index |
| global-workflow.md | **Legacy** — superseded by `gw-triage`, `gw-riskcheck`, `gw-handoff`, `gw-review`; kept for reference only |
