# Git Finalize

**Purpose:** Close the current execution branch by merging to main after all quality and documentation gates pass.

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

**Workflow:**

1. Verify on a feature branch (refuse if on `main`).
2. Verify working tree is clean (no uncommitted changes).
3. Run quality checks:
   - Tests pass (project-appropriate command).
   - Lint passes (project-appropriate command).
   - Locks intact (if applicable to project).
4. Verify documentation obligations met:
   - `/session-end` was run (HANDOFF.md updated).
   - `/docpack` was run if documentation was part of the deliverable.
5. Push the branch to remote.
6. Merge branch into `main` (fast-forward or merge commit).
7. Push `main`.
8. Delete the branch (local + remote).
9. Report: merge commit hash, branch deleted, "Execution closed".

**Non-Negotiable Guards:**

- Refused if tests fail.
- Refused if lint fails.
- Refused if `/session-end` was not run.
- Refused if on `main` (nothing to finalize).
- Branch is ALWAYS deleted after successful finalize.

**Output:** Merge commit hash, branch name deleted, "Execution closed".
