# Git Finalize

**Purpose:** Solo / direct workflow — commit all local work on the feature branch, merge into `main` locally, push `main` to `origin`, delete the feature branch locally. If **`git push origin main` fails**, still delete the local feature branch and report so you can fix the network or remote and push later.

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Workflow (ordered)

Use `<branch>` = current feature branch name. Refuse if you are on `main`.

### 1. Quality checks (before merge)

- Tests pass (e.g. `php artisan test`).
- Lint passes (e.g. `./vendor/bin/pint --test`).
- `/session-end` done when applicable (`HANDOFF.md`).
- `/docpack` when docs or conventions changed.

### 2. Commit everything on the feature branch

Still on `<branch>`:

- Stage and commit **all** changes you intend to keep (tracked + untracked), e.g. `git add -A` then `git commit -m "…"`.
- **Never** commit secrets: `.env`, keys, tokens. Respect `.gitignore`.
- If there is nothing to commit, skip this step.

### 3. Merge into `main` (local only)

```
git fetch origin
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
```

Resolve conflicts if any; do not push yet until merge is clean.

### 4. Push `main` to `origin`

```
git push -u origin main
```

- **If this succeeds:** note success.
- **If this fails (network, auth, remote):** record the error, **continue to step 5 anyway.** You can run `git push origin main` from `main` after fixing the issue; your merge is already local.

### 5. Delete the local feature branch (always)

```
git branch -d <branch>
```

Use `-D` only if Git says the branch is not fully merged (should not happen after a clean merge).

### 6. Optional: remove remote copy of the feature branch

If that branch exists on `origin` and is not the host default branch:

```
git push origin --delete <branch>
```

If the host refuses (e.g. default branch still set to that branch), change default to `main` on GitHub/GitLab, then retry.

---

## When there is no `origin`

After step 3, stop before push. Still run step 5 (delete local `<branch>`). Report: push when remote exists.

---

## Guards

- Refuse if on `main` (nothing to finalize).
- Refuse if tests or lint fail (fix first, then finalize).
- Prefer refusing if `/session-end` was skipped for a session that changed project state (solo exception: user may override).

## Merge style

Prefer `--no-ff` so the feature branch stays visible in history.

## Output

Report: commits made (if any), merge commit hash, push result (ok / failed — branch still deleted locally), confirmation local `<branch>` removed.
