# Git Finalize

**Purpose:** Close the current execution branch by merging to main after all quality and documentation gates pass.

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Workflow

### 1. Verify preconditions

- On a feature branch (refuse if on `main`).
- Working tree clean: no modified or staged tracked files. **Untracked files are allowed.**

### 2. Quality checks

- Tests pass (project-appropriate command, e.g. `php artisan test`).
- Lint passes (project-appropriate command, e.g. `./vendor/bin/pint --test`).
- Locks intact (if applicable; see `.cursor/memory/INTEGRITY_RULES.md` or project conventions).

### 3. Documentation obligations

- `/session-end` was run (HANDOFF.md updated).
- `/docpack` was run if documentation was part of the deliverable (conventions, test helpers, API changes, etc.).

### 4. Merge and close

**Path A: Remote exists, direct merge allowed**

```
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
git push origin main
git branch -d <branch>
```

Do **not** push the feature branch to remote; merge locally, push main only, then delete the local branch. This avoids pushing a branch that is immediately merged and deleted.

**Path B: Remote exists, PR required**

Project rules require merge via pull/merge request:

```
git push -u origin <branch>
```

Report: "Branch pushed. Create PR from `<branch>` to `main`. Merge via host, then delete locally: `git checkout main && git pull && git branch -d <branch>`". Stop. Agent does not merge or delete.

**Path C: No remote**

```
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
git branch -d <branch>
```

Report: "Merged locally. When remote is configured: `git push origin main`".

### 5. Report

Merge commit hash, branch deleted (or PR instruction), "Execution closed".

---

## Non-Negotiable Guards

- Refused if tests fail.
- Refused if lint fails.
- Refused if `/session-end` was not run.
- Refused if on `main` (nothing to finalize).
- Branch is deleted after successful finalize (Path A or C); Path B leaves deletion to human after PR merge.

---

## Merge style

Use `--no-ff` (merge commit) for feature branches to preserve branch identity in history. Omit if project prefers fast-forward.

---

## Output

- **Path A/C:** Merge commit hash, branch name deleted, "Execution closed".
- **Path B:** Branch pushed, PR instruction, "Awaiting merge via host".
