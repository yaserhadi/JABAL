# Git Save

**Purpose:** Commit current work inside the branch without merging or pushing. A safe checkpoint.

**Related commands:** `/git-prepare`, `/git-finalize`, `/gw-handoff`, `/session-end`

**Workflow:**

1. Verify NOT on `main` (refuse if on main).
2. Check for staged/unstaged changes; if none, report "Nothing to save" and stop.
3. `git add` relevant changes (or all if user confirms).
4. `git commit` with a descriptive message (ask user or generate from context).
5. Do NOT push. Do NOT merge.
6. Report: commit hash, files committed, "Progress saved".

**Guards:**

- Refused on `main`.
- Refused if no changes exist.
- Never pushes or merges.

**Output:** Commit hash, list of files committed, "Progress saved".
