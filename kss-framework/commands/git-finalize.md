# Git Finalize

**Purpose:** Close a feature branch either with a **solo/direct** merge to `main` (default for single dev), or with a **PR-only** handoff when governance requires pull/merge request review before `main` updates.

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Choose path first

| Path | When to use |
|------|----------------|
| **Path A — Direct merge (default)** | Solo or team allows merging locally and pushing `main`. |
| **Path B — PR required** | Project rules require code review via pull/merge request; do **not** merge to `main` locally — push the feature branch only, then merge on the host. |

If unsure, use **Path B** for shared org repos; use **Path A** when you are the only maintainer and policy allows it.

---

## Shared steps (both paths)

Use `<branch>` = current feature branch name. **Refuse if you are on `main`.**

### 1. Quality checks (before merge or push)

- Tests pass (e.g. `php artisan test`).
- Lint passes (e.g. `./vendor/bin/pint --test`).
- `/session-end` when applicable (`HANDOFF.md`).
- `/docpack` when docs or conventions changed.

### 2. Commit everything on the feature branch

Still on `<branch>`:

- Stage and commit intended work, e.g. `git add -A` then `git commit -m "…"`.
- **Never** commit secrets: `.env`, keys, tokens. Respect `.gitignore`.
- If there is nothing to commit, skip this step.

---

## Path A — Direct merge to `main` (solo / allowed)

### A3. Merge into `main` locally

**`git fetch` only if `origin` exists** (otherwise `fetch` errors and blocks the merge):

- If `origin` is configured: `git fetch origin`
- If there is **no** `origin` remote: **skip** `git fetch`; continue with checkout + merge only.

Then:

```
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
```

Resolve conflicts before continuing.

### A4. Push `main` (only if `origin` exists)

- If **no** `origin`: skip this step. Report: merge is local only; add remote and run `git push -u origin main` later.
- If **`origin` exists:**
  ```
  git push -u origin main
  ```
  - **If push succeeds:** note success.
  - **If push fails:** record the error; **still continue to A5.** Retry push from `main` after fixing network/auth.

### A5. Delete the local feature branch (always after a successful local merge)

```
git branch -d <branch>
```

Use `-D` only if Git reports the branch is not fully merged (unexpected after a clean merge).

### A6. Optional: delete remote `<branch>`

If `<branch>` exists on `origin` and is not the host default branch:

```
git push origin --delete <branch>
```

If the host refuses, set default branch to `main`, then retry.

---

## Path B — PR / governance required

Do **not** check out `main` or merge locally.

After shared steps 1–2:

```
git push -u origin <branch>
```

Then **stop**. Report:

- Open a PR from `<branch>` → `main` on the host.
- After the PR is merged: `git checkout main && git pull origin main && git branch -d <branch>` (and remove remote branch if needed).

---

## Guards

- Refuse if on `main` (nothing to finalize).
- Refuse if tests or lint fail (fix first, then finalize).
- Prefer refusing if `/session-end` was skipped when project state changed (exception: explicit override).

## Merge style

Prefer `--no-ff` so the feature branch stays visible in history.

## Output

- **Path A:** Commits made (if any), merge commit hash, whether `fetch`/`push` ran or was skipped, push ok/failed, local `<branch>` deleted.
- **Path B:** Branch pushed; PR instructions; no local merge.
