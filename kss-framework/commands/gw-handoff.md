# Handoff

**Purpose**: Summarize changes, file list, tests, and next steps for human review.

**Related Commands**: `/gw-triage`, `/gw-riskcheck`, `/gw-review`

---

**Workflow**:
1. Collect all changes made in the current session
2. List files by action (added, edited, removed)
3. Summarize what changed and why
4. List verification steps and tests
5. Define next steps for human reviewer

**Output Format**:
```
## Handoff Summary

**Session Summary**:
[1-2 sentence overview of what was accomplished]

**Files Changed**:

| Action | File | Description |
|--------|------|-------------|
| Add | path/to/file.ext | [what was added] |
| Edit | path/to/file.ext | [what was changed] |
| Remove | path/to/file.ext | [why removed] |

**What Changed and Why**:
- [Change 1]: [reason]
- [Change 2]: [reason]
- [Change 3]: [reason]

**Tests to Run**:
- [ ] [Test command or step 1]
- [ ] [Test command or step 2]
- [ ] [Manual verification step]

**Next Steps for Reviewer**:
1. [Action item 1]
2. [Action item 2]
3. [Action item 3]

**Open Questions / Decisions Needed**:
- [Any unresolved items requiring human decision]

**Evidence Referenced**:
- [files/commands/tests that support the conclusions]

**ADR Status**:
- ADR Needed? [Yes / No]
- Decision Indicators: [list what triggered / N/A]
- ADR Coverage: [ADR-XXXX / None / N/A]

> If ADR Needed = Yes and Coverage = None:
> Recommend running `/adr` before completing handoff (advisory).
```

---

## Notes

- This command complements the `safe-coding-practices` skill
- When in doubt, prefer safety over speed
- Always cite evidence for findings
- Stop and ask if context is missing
