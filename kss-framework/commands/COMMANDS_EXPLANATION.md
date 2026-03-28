# Commands — Copy Instructions

**Destination:** `~/.cursor/commands/`

| File | Purpose | When to Use |
|------|---------|-------------|
| boot.md | Load project context | Start of every session |
| session-end.md | Save handoff to HANDOFF.md | Before closing chat |
| docpack.md | Update docs after work | End of session, after changes |
| gw-triage.md | Triage unclear request; **MODULE-CREATION-GATE** (module destination); detect architectural decisions | Request is ambiguous, may involve ADR, or needs existing module vs kernel vs new module |
| gw-riskcheck.md | Assess risks, CAB flag; includes ADR check | Before production/security changes |
| gw-review.md | Review changes; catch unrecorded decisions | Before merge |
| gw-handoff.md | Summarize for reviewer | In-session handoff |
| git-prepare.md | Create branch from main | Start new work |
| git-save.md | Commit progress | Safe checkpoint |
| git-finalize.md | Merge and delete branch | After tests pass |
| en.md | English-only reply | Override language |
| ar.md | Arabic-only reply | Override language |
| aren.md | Dual language reply | Override language |
| adr.md | Create ADR from text | Architecture decisions |
