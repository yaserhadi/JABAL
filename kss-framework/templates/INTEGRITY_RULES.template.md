# Integrity Rules (Non-negotiable)

<!-- Copy to: .cursor/memory/INTEGRITY_RULES.md -->
<!-- These are guardrails that cannot be bypassed. -->

1. No ADR files under `.cursor`. ADRs are allowed only in `docs/architecture/ADR/`.
2. No [TBD] in active memory files.
3. DOC_POLICY contains paths only.
4. Human ADRs live in docs/architecture/ADR/. Enforcement layers (MANIFEST, etc.) are authority.
5. MANIFEST contains only constraints and locks, not discussions.
6. HANDOFF is replace-only and must be backed up before overwrite.

---

## ADR Governance (Human Deliberation)

1. ADR files are allowed ONLY in: docs/architecture/ADR/
2. No ADR files may exist under .cursor/ (including memory, rules, plans, reports).
3. ADRs are non-authoritative for execution.
   Authoritative enforcement layers are:
   - PROJECT_MANIFEST.md
   - INTEGRITY_RULES.md
   - VERSIONS.md
   - STATE.yaml
   If conflict occurs, enforcement layers win.
4. ADR creation must never automatically modify enforcement layers.
   If ADR Status = Final, produce an Enforcement Sync Proposal and wait for explicit approval.
5. ADR is not part of the execution reading chain. Agents do not derive behavior from ADR unless the user explicitly requests reading it.

---

## Periodic Drift Check (run manually)

```
rg -n '[TBD]' .cursor docs
rg -n 'ADR-' .cursor
rg -n 'authority_order|modes:' .cursor
```

Any result outside archive requires review.

---

## Domain Locks Verification (project fills)

<!-- For each lock in PROJECT_MANIFEST, add verification commands:

### Lock: <LOCK_ID>

**Verification commands:**

```
rg "<forbidden_pattern>" <path>
```
Expected: empty (pattern must not exist)

```
rg "<required_pattern>" <path>
```
Expected: matches found (pattern must exist)

**Stop conditions:**
- If <condition> → STOP

-->

---

## Conventions Governance

Engineering conventions live in `.cursor/memory/conventions/`.

**Authority Rules:**

| Aspect | Rule |
|--------|------|
| Location | ONLY in `.cursor/memory/conventions/` |
| Status | Advisory by default |
| Override | Enforcement layers (MANIFEST, INTEGRITY_RULES, VERSIONS, STATE) always win |

**Promotion to Enforcement:**

If a convention contains enforceable constraints (MUST/forbidden/required), they must be:
1. Extracted as a Lock in PROJECT_MANIFEST, OR
2. Added as a gate in INTEGRITY_RULES, OR
3. Pinned in VERSIONS

Conventions alone do NOT grant enforcement authority.

---

## Plan Governance Validation

Before executing any plan in `.cursor/plans/`:

```
rg -l "## Governance Reference" .cursor/plans/*.plan.md
rg "status:" .cursor/memory/STATE.yaml
```

**Validation Rules:**

| Check | Requirement |
|-------|-------------|
| Governance Reference section | MUST exist in active plan |
| Lock reference | MUST point to valid MANIFEST lock |
| STATE.yaml status | If `manual-oversight` → owner approval required |
| STATE.yaml status | If `blocked` → do NOT execute |

If any check fails → STOP and request owner approval.

---

## ACTIVE.plan.md Consistency

ACTIVE.plan.md MUST always reflect current execution state.

**On Plan Completion (mandatory):**

When ALL todos in a plan are marked `completed`:
1. Update ACTIVE.plan.md "Current active plan" to next plan or "None"
2. Move completed plan to "Last completed plan" with status and date
3. Archive the completed plan file to `.cursor/plans/archive/`
