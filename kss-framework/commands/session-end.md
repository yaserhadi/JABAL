# Session End

**Purpose**: Gracefully end a work session by generating a handoff summary and persisting it to `.cursor/memory/HANDOFF.md`.

**Related Commands**: `/boot`, `/gw-handoff`, `/docpack`

---

## Workflow

### 1. Read Paths
- Read `.cursor/DOC_POLICY.yaml` for paths only (memory, reports, etc.)
- If missing: use defaults (.cursor/memory/, .cursor/reports/). Always persist handoff.

### 2. Generate Handoff Summary
Collect from the current session:
- **Session Summary**: 1-2 sentence overview of what was accomplished
- **Test Status**: If tests or migrations were run, record result (PASS/FAIL/Pending) with count; otherwise mark Pending
- **Files Changed**: List by action (Add/Edit/Remove)
- **Key Decisions Made**: Any architectural or design choices
- **Open Issues**: Unresolved items or blockers
- **Next Steps**: What should the next session focus on

### 3. Update .cursor/memory/HANDOFF.md

**Before replacing HANDOFF.md:**
- Ensure `.cursor/reports/` exists; create it if missing.
- Append the previous HANDOFF content (with a date separator) to `.cursor/reports/HANDOFF_BACKUP_YYYY-MM.md` so multiple sessions in the same month are preserved.

Replace the contents of `.cursor/memory/HANDOFF.md` with the new handoff summary.

**Important**: Do NOT append. Replace the entire file so it always reflects the most recent session.

### 4. Optional: Trigger Stewards
If significant changes were made:
- Suggest running `/docpack` for full documentation update
- Note any lessons in HANDOFF "What to Avoid" or .cursor/memory/LESSONS.md
- Architecture decisions → docs/architecture/ADR/

### 5. Confirm Completion
Display:
```
## Session Ended

**Handoff saved to**: .cursor/memory/HANDOFF.md

**Summary**: [1-line summary]

**Next session**: Run `/boot` to continue from here.
```

---

## Output Format

```
## Session Handoff

**Date**: [current date]

### Session Summary
[1-2 sentence overview of what was accomplished]

### Test Status
| Verification | Status | Result |
|--------------|--------|--------|
| php artisan test | ✅ PASS / ⏳ Pending / ❌ FAIL | [e.g. 70 tests, 153 assertions] |
| php artisan migrate:fresh | ✅ PASS / ⏳ Pending / ❌ FAIL | [if migrations changed] |

### Files Changed
| Action | File | Description |
|--------|------|-------------|
| Add | path/to/file | [description] |
| Edit | path/to/file | [description] |
| Remove | path/to/file | [description] |

### Key Decisions
- [Decision 1]
- [Decision 2]

### ADR Closing Check (Advisory)
- [ ] Decisions made this session: [list / none]
- [ ] ADR captured: [ADR-XXXX / None needed / Not yet]
- [ ] If decisions exist and ADR not captured:
  - Recommend: Run `/adr` to record the decision
  - Or document: "ADR Not Needed: <reason>"

> This check is advisory only. It does NOT block session-end.

### Open Issues
- [Issue or blocker, if any]

### Next Steps
1. [Priority action for next session]
2. [Secondary action]
3. [Optional action]

### Context for Next Agent
[Any important context the next agent needs to know that isn't captured above]
```

---

## Example Usage

At the end of your work session:
```
/session-end
```

The command will:
1. Summarize all work done in this session
2. Save the handoff to `.cursor/memory/HANDOFF.md`
3. Confirm the session is ready for continuation

---

## Notes

- Run this command BEFORE closing your chat session
- The next session should start with `/boot` to load this context
- If you forget to run this, the next agent won't have session continuity
- This command complements `/gw-handoff` but adds persistence
