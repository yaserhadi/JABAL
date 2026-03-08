# Git Prepare

**Purpose:** Begin execution on a new branch from main. Never work directly on main.

**Related commands:** `/git-save`, `/git-finalize`, `/gw-handoff`, `/session-end`, `/docpack`

**Workflow:**

1. Check if on `main` with uncommitted changes; if so, stash them (with descriptive name).
2. Pull/update `main` to latest.
3. Create a new branch from `main` (name based on task or user input).
4. Switch to the new branch.
5. Report: branch name, stash status, "Ready to work".

**Guards:**

- Must create branch from `main` (not from another feature branch).
- Must not allow working directly on `main`.
- If stash was needed, report it clearly.

**Output:** Branch name, stash status (if any), confirmation "Ready to work".
