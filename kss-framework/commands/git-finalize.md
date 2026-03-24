# Git Finalize

**Purpose:** Close a feature branch **locally only**: commit work, merge into `main`, delete the feature branch. **Does not use remotes** — no `fetch`, `push`, or `origin`. Sync with a remote yourself when you want (e.g. `git push origin main`).

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Workflow

`<branch>` = current feature branch. **Refuse if on `main`.**

### 1. Quality checks

- Tests pass (e.g. `php artisan test`).
- Lint passes (e.g. `./vendor/bin/pint --test`).
- `/session-end` when applicable (`HANDOFF.md`).
- `/docpack` when docs or conventions changed.

### 2. Commit on `<branch>`

- `git add -A` then `git commit -m "…"` (or equivalent) for all intended changes.
- **Never** commit `.env`, keys, or tokens; respect `.gitignore`.
- Skip if nothing to commit.

### 3. Merge into `main` (local)

```
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
```

Resolve any conflicts before continuing.

### 4. Delete local feature branch

```
git branch -d <branch>
```

Use `-D` only if Git says the branch is not fully merged after a correct merge.

### 5. Report

Commits made (if any), merge commit hash, local `<branch>` removed. Remind: **remote sync is out of scope for this command.**

---

## Guards

- Refuse if on `main`.
- Refuse if tests or lint fail.
- Prefer refusing if `/session-end` was skipped when project state changed (override only if explicit).

## Merge style

Prefer `--no-ff` for clear feature history.
