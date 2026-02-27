# KSS Minimal Model

## Why This System Exists

AI agents need persistent memory across sessions. Humans need governance without ceremony.

## Core Memory Files (4)

| File | Purpose |
|------|---------|
| PROJECT_MANIFEST.md | Vision, constraints, architectural locks |
| STATE.yaml | Execution state (phase, stage, status) |
| HANDOFF.md | Session continuity, blockers, lessons |
| VERSIONS.md | Version facts |

## How Agents Interact

1. Read DOC_POLICY.yaml for paths
2. Read AI_ENTRY.md for rules
3. Read memory files in order
4. Write to HANDOFF + STATE (conditional)
5. Create human ADRs in docs/architecture/ADR/ via /adr command. Do not auto-update enforcement layers.
6. Reports go to .cursor/reports/

## Authority

DOC_POLICY > MANIFEST > docs/architecture/ADR (human, non-authoritative) > STATE > HANDOFF > reports
