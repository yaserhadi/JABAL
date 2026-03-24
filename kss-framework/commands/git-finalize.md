# Git Finalize

**Purpose:** Close a feature branch using **only local Git operations**: commit on the feature branch, merge into local `main`, delete the local feature branch.

## Assumption: local repository only

This command describes **no network, no remotes, no `origin`**: no `fetch`, `pull`, `push`, or host (GitHub/GitLab) steps. It assumes a normal clone or repo on disk with at least local branches `main` and `<branch>`. Anything after that (backup, publish) is separate and **not** part of `/git-finalize`.

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Workflow

`<branch>` = current feature branch name. **Refuse if you are on `main`.**

### 1. Quality checks

- Tests pass (e.g. `php artisan test`).
- Lint passes (e.g. `./vendor/bin/pint --test`).
- **`/session-end`:** Before steps 2–4 (while on `<branch>`), when this session materially changes the code you merge into `main`, update `.cursor/memory/HANDOFF.md` so it matches that work.
- **`/docpack`:** Same timing when conventions or project docs need updates. Skip if nothing doc-related changed.

### 2. Commit on `<branch>`

```
git add -A
git commit -m "<message>"
```

- Adjust staging if you must not include some paths; **never** commit `.env`, keys, or tokens; respect `.gitignore`.
- Skip this step if there is nothing to commit.

### 3. Merge into local `main`

```
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
```

Resolve conflicts before continuing.

### 4. Delete the local feature branch

```
git branch -d <branch>
```

Use `-D` only if Git reports the branch is not fully merged after an intentional merge.

### 5. Report

Report only local outcomes: commits made (if any), merge commit hash on `main`, confirmation that local `<branch>` was deleted. **Do not** run or assume any remote commands as part of this workflow.

---

## Guards

- Refuse if on `main`.
- Refuse if tests or lint fail.
- Prefer refusing if `/session-end` was skipped while meaningful state changed and you are merging that work into `main` (unless explicitly overridden for trivial work).

## Merge style

Prefer `git merge <branch> --no-ff` so feature work stays visible in history.
