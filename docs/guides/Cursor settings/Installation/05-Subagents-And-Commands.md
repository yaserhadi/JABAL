# 05 — Subagents and Commands

**Purpose:** Install 3 subagents and 14 commands.

## Subagents (agents/)

Copy to `~/.cursor/agents/`:

- adr-steward.md
- ai-knowledge-steward.md
- user-doc-steward.md

## Commands (commands/)

Copy to `~/.cursor/commands/`:

- boot.md, session-end.md, docpack.md
- gw-triage.md, gw-riskcheck.md, gw-review.md, gw-handoff.md
- git-prepare.md, git-save.md, git-finalize.md
- en.md, ar.md, aren.md

**Project command:** adr.md — copy to project `.cursor/commands/`

## Quick Reference

| Command | When |
|---------|------|
| /boot | Start session |
| /session-end | Before closing chat |
| /docpack | End of session |
| /gw-triage | Unclear request |
| /gw-riskcheck | Before risky change |
| /gw-review | Before merge |
| /adr | Create ADR from pasted text |
