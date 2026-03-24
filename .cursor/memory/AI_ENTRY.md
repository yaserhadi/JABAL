# AI ENTRY — JABAL

**Mandatory entry gate** for any AI agent. Defines reading order, authority, and execution rules.

---

## Mandatory Reading Order (Do Not Skip)

Before doing **anything else**, read the following files **in order**:

1. **`.cursor/DOC_POLICY.yaml`**  
   Canonical paths only (DOC_POLICY contains no governance logic).

2. **`.cursor/memory/PROJECT_MANIFEST.md`**  
   Project brain: vision, constraints, conceptual phases, governance.

3. **`.cursor/memory/STATE.yaml`**  
   Execution dashboard: where we are now and whether execution is allowed.

4. **`.cursor/memory/HANDOFF.md`**  
   Session continuity: literal next actions and what to avoid.

5. **`.cursor/goals/GOALS.md`**  
   Strategic goals only. No execution, no phases, no tasks. **Mandatory** — read with an explicit path; do not infer absence from glob/search alone.

> ⚠️ **WARNING**  
> Do NOT begin execution before reading `PROJECT_MANIFEST.md`.  
> Reading STATE or HANDOFF first is insufficient.

> If HANDOFF.md is missing, empty, or shorter than 10 lines, STOP and request confirmation before proceeding.

> If `GOALS.md` is missing or cannot be read, STOP — boot and mandatory startup cannot complete until `.cursor/goals/GOALS.md` is restored or the path is fixed.

Before execution, also read **`.cursor/memory/INTEGRITY_RULES.md`** to enforce non-negotiable guardrails (ADR governance: no ADR under `.cursor`; ADRs live only in `docs/architecture/ADR/`, no [TBD], paths-only DOC_POLICY, etc.).

When making **version-sensitive or technical decisions** (e.g. framework, runtime, database, deployment), also read **`.cursor/memory/VERSIONS.md`** so you do not assume unconfirmed versions.

When working on **tenancy, database, or multi-tenant features**, also read:
- The "Lock: TENANCY-DUAL-DB" section in `PROJECT_MANIFEST.md`
- The "Domain Locks Verification" section in `INTEGRITY_RULES.md`

Run verification commands before proposing changes.

---

## Authority

When information conflicts: DOC_POLICY (paths) > MANIFEST (constraints) > INTEGRITY_RULES (guardrails) > docs/architecture (human ADRs; non-authoritative for execution) > STATE > HANDOFF > GOALS. Anything not written is not a truth.

---

## ADR Handling Protocol

When user invokes /adr:
1. Create or update ADR in docs/architecture/ADR/
2. Never modify: PROJECT_MANIFEST, INTEGRITY_RULES, STATE, VERSIONS
3. If ADR Status = Final: provide separate "Enforcement Sync Proposal"; wait for explicit approval before modifying enforcement layers
4. If enforcement layers contradict ADR: inform user; do not auto-resolve

ADR files are human deliberation records and are not part of the execution reading chain.

---

## Execution Rules

- Execution state lives **only** in `STATE.yaml`
- Vision, constraints, and governance live **only** in `PROJECT_MANIFEST.md`
- Session continuity lives **only** in `HANDOFF.md`
- Goals live **only** in `.cursor/goals/GOALS.md`

Do NOT:
- Add execution state to MANIFEST
- Add goals to STATE
- Add rules to HANDOFF
- Create new memory locations or shadow folders (except those declared in DOC_POLICY)

---

## Approved Memory Subfolders

The following subfolders under `.cursor/memory/` are declared in DOC_POLICY and are NOT shadow folders:

| Subfolder | Purpose | Authority |
|-----------|---------|-----------|
| `conventions/` | Engineering standards (API, DB contracts) | Advisory only; does not override enforcement layers |

**Reading rules for conventions:**
- Conventions are NOT mandatory in boot chain
- Read conventions ONLY when task touches API/DB design
- If convention conflicts with MANIFEST/INTEGRITY_RULES, enforcement wins

---

## How to Start Working (Agent Loop)

1. Read all mandatory files (in order).
2. Check `STATE.yaml`.
   - If `execution.status` is **not** `active`, **do not execute**.
3. If execution is **not allowed** (`status != active`):
   - Do **not** write code.
   - Do **not** invent tasks or side work.
   - Only do one of the following **if explicitly requested**:
     - Clarify questions and record them in HANDOFF Open Questions
     - Update documentation **only if instructed**
   - Then stop.
4. If `execution.status` is `active`:
   - Read `HANDOFF.md`
   - Execute **only** the literal next actions described there
   - Stay within MANIFEST constraints
5. Before ending the session:
   - Update `STATE.yaml` **only if execution state changed**
   - Write `HANDOFF.md` using `/session-end`
     - **Use Agent mode** (Plan and Ask modes cannot write HANDOFF)

---

Paths: see DOC_POLICY.yaml. Do not create shadow locations.

HANDOFF is replaced each session (/session-end); do not append.
