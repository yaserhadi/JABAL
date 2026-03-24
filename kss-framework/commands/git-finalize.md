# Git Finalize

**Purpose:** Close a feature branch **locally only**: commit work, merge into `main`, delete the feature branch. **Does not use remotes** — no `fetch`, `push`, or `origin`. Sync with a remote yourself when you want (e.g. `git push origin main`).

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Workflow

`<branch>` = current feature branch. **Refuse if on `main`.**

### 1. Quality checks

- Tests pass (e.g. `php artisan test`).
- Lint passes (e.g. `./vendor/bin/pint --test`).
- **`/session-end`:** Run **before** steps 2–4 (still on `<branch>`) whenever this session meaningfully changes the code you are about to merge into `main`, so `.cursor/memory/HANDOFF.md` matches the work that will land on `main`.
- **`/docpack`:** Same timing — before merge, when conventions or project docs need updates for this work. Skip only if nothing documentation-related changed.

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
- Prefer refusing if `/session-end` was skipped while meaningful state changed **and** you are using this command to merge that work into `main` (HANDOFF should precede or align with the merge). Solo or trivial-only sessions may override explicitly.

## Merge style

Prefer `--no-ff` for clear feature history.
