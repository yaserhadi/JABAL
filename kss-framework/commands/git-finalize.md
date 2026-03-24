# Git Finalize

**Purpose:** Close the current execution branch by merging to `main`, pushing `main` to the remote, and deleting the feature branch.

**Related commands:** `/git-prepare`, `/git-save`, `/session-end`, `/docpack`, `/gw-handoff`

## Default workflow (remote exists)

Merge locally, push **`main` only** (do not push the feature branch), delete the feature branch locally, then delete it on the remote if possible.

```
git fetch origin
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
git push -u origin main
git branch -d <branch>
git push origin --delete <branch>   # if branch exists on remote; see caveat below
```

**Why not push the feature branch first?** Avoids a redundant remote branch that would be merged and removed immediately.

## When no remote (Path C)

```
git checkout main
git merge <branch> --no-ff -m "Merge <branch> into main"
git branch -d <branch>
```

Report: merged locally; run `git push -u origin main` when remote is ready.

## Optional: PR-only merges (Path B)

Use **only** when project policy requires a pull/merge request and merging must happen on the host:

```
git push -u origin <branch>
```

Then stop: open PR, merge via host, update local `main`, delete local feature branch. Do **not** use Path B when the goal is to merge and push `main` directly.

## Preconditions

1. On a feature branch (refuse if on `main`).
2. Tracked files: no uncommitted modifications or staged changes. **Untracked files are allowed.**

## Quality checks

- Tests pass (e.g. `php artisan test`).
- Lint passes (e.g. `./vendor/bin/pint --test`).
- Locks intact if applicable (see `.cursor/memory/INTEGRITY_RULES.md`).

## Documentation

- `/session-end` was run (`HANDOFF.md` updated).
- `/docpack` when documentation changed (conventions, test helpers, APIs, etc.).

## Remote delete caveat

If `git push origin --delete <branch>` fails with **refusing to delete the current branch**, the host’s **default branch** is still that feature branch. On GitHub/GitLab: **Settings → Default branch → `main`** → then delete the remote feature branch (or retry the push delete).

## Merge style

Prefer `git merge <branch> --no-ff` to keep feature history explicit. Use fast-forward only if the team prefers it.

## Guards

- Refuse if tests or lint fail.
- Refuse if `/session-end` was not run.
- Refuse if on `main`.
- After success: local feature branch removed; remote `main` updated.

## Output

Report merge commit hash, confirm `main` pushed, branch deleted (local and remote, or note if remote delete blocked by default-branch setting).
