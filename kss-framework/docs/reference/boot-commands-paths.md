# Boot Commands — Canonical Paths (Single Memory)

**Purpose:** All `/boot`, `/docpack`, and `/session-end` commands must use these paths so only one memory location (`.cursor/memory/`) is used. Do not reference root `memory/`.

**Canonical policy file:** `.cursor/DOC_POLICY.yaml`
**Canonical memory directory:** `.cursor/memory/`

## Path substitutions

Apply these in your Cursor command files (copy to `~/.cursor/commands/`):

| Old | New |
|-----|-----|
| `memory/DOC_POLICY.yaml` | `.cursor/DOC_POLICY.yaml` |
| `memory/AI_ENTRY.md` | `.cursor/memory/AI_ENTRY.md` |
| `memory/STATE.yaml` | `.cursor/memory/STATE.yaml` |
| `memory/HANDOFF.md` | `.cursor/memory/HANDOFF.md` |
| `memory/LESSONS.md` | `.cursor/memory/LESSONS.md` |
| `memory/` (as directory) | `.cursor/memory/` |

## Files to update

- **boot** (e.g. `boot.md`): Check policy at `.cursor/DOC_POLICY.yaml`; read `.cursor/memory/AI_ENTRY.md` (mandatory order: DOC_POLICY, then PROJECT_MANIFEST, STATE, HANDOFF, GOALS), then `.cursor/memory/PROJECT_MANIFEST.md`, `.cursor/memory/STATE.yaml`, `.cursor/memory/HANDOFF.md`, and **`.cursor/goals/GOALS.md` (mandatory — Read with explicit path; if read fails, STOP boot)**. The agent must read PROJECT_MANIFEST to receive the project brain. Do not use glob to skip GOALS. On-demand sources include `docs/architecture/ADR/README.md` for architecture decisions. `.cursor/` can remain gitignored; direct path reads are authoritative for KSS files.
- **docpack** (e.g. `docpack.md`): Use `.cursor/DOC_POLICY.yaml` and write/read from `.cursor/memory/` as needed.
- **session-end** (e.g. `session-end.md`): Write handoff to `.cursor/memory/HANDOFF.md` only.

## Verification

After updating commands, run `/session-end` and confirm that only `.cursor/memory/HANDOFF.md` is created or updated (not any file under root `memory/`).

**Single-memory-path fix:** Commands updated; content migrated to `.cursor/memory/`; root `memory/` deprecated (README only). **You should run `/session-end` once and confirm only `.cursor/memory/HANDOFF.md` is updated.**
