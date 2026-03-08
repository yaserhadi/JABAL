# /adr — Architectural Decision Record (Human Deliberation)

## Input (required)
Paste the source text (ChatGPT discussion, Cursor chat, meeting notes, etc.).
Optional: title/slug suggestion, owner override.

Owner:
- Defaults to "KSS Steward - John"
- May be overridden explicitly by user
- Must never default to an AI agent name

ADR ownership represents human accountability. AI tools may generate content but do not own decisions.

## Purpose
Generate a Human ADR in:
- docs/architecture/ADR/

ADR is human-facing deliberation and is **non-authoritative** for execution.

This command MUST NOT:
- Modify PROJECT_MANIFEST.md
- Modify INTEGRITY_RULES.md
- Modify STATE.yaml
- Modify VERSIONS.md
- Create shadow memory folders
- Change any execution logic

## Workflow (text extraction)
1) Ask the user to paste the source text (if not provided).
2) Invoke the ADR extraction agent (adr-steward) with the pasted text.
3) adr-steward extracts:
   - Context
   - Options considered
   - Decision (if present)
   - Consequences
   - Risks & Controls
   - Status (Draft | Final)
   - Owner (default: KSS Steward - John; override if user provides). If Owner not provided → set Owner: KSS Steward - John
   - Enforcement Impact (what must be mirrored in enforcement layers)
4) Create or update:
   docs/architecture/ADR/ADR-XXXX-slug.md
   - sequential numbering (use next available number)
5) If Status = Final:
   - Produce an "Enforcement Sync Proposal" text block
   - DO NOT apply it automatically
   - Wait for explicit approval before any enforcement update

## Output
- ADR file only + (optional) enforcement sync proposal (not applied).
