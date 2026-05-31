# Governance workflow commands (`/gw-*`)

**Audience:** Developers and operators using Cursor with KSS on a project.

**Purpose:** Explain **when** to use each `/gw` command, the **typical order**, and where to find full agent specifications.

**This page is a human guide.** It does not replace the command files in [`kss-framework/commands/`](../../commands/) — those define agent output formats and mandatory checks.

---

## Quick reference (cheat sheet)

Use this table to pick a command. **Details for each command are below.**

| Situation | Command |
|-----------|---------|
| “What should we do? Where should this code live?” | `/gw-triage` |
| “Is this safe to proceed? Need CAB approval?” | `/gw-riskcheck` |
| “Is this diff ready to merge?” | `/gw-review` |
| “Summarize this session for me or a reviewer” | `/gw-handoff` |

**Memory hook:** **T**riage → plan · **R**iskcheck → safe? · **R**eview → merge? · **H**andoff → summary

---

## Typical order (feature work)

Not every task needs all four commands. For non-trivial product or infrastructure work, this order is a safe default:

```text
/gw-triage  →  /gw-riskcheck  →  (implement)  →  /gw-review  →  /gw-handoff
```

| Step | Command | Role |
|------|---------|------|
| 1 | `/gw-triage` | Clarify scope, propose safest plan, module destination, ADR needed? |
| 2 | `/gw-riskcheck` | Assess security, data, production impact; CAB and rollback |
| 3 | *(work)* | Implement on a feature branch |
| 4 | `/gw-review` | Review diff before merge — safety, security, boundaries |
| 5 | `/gw-handoff` | Session summary — files, tests, next steps |

**Skip or shorten when:**

- **Read-only / docs-only** — often no `/gw-riskcheck` unless the doc changes governance or security policy.
- **Tiny, reversible fix** — triage may be enough; still run `/gw-review` before merge if touchy areas (auth, tenancy, DB).
- **End of session** — use `/gw-handoff` for a quick summary; use `/session-end` when you need HANDOFF persisted to `.cursor/memory/`.

---

## Commands in detail

### `/gw-triage`

**In plain terms:** Figure out what to do and where it belongs before you code.

**Use when:**

- The request is unclear or large.
- You need to decide where work belongs (e.g. existing module, kernel, or new module) — follow the adopting project's `.cursor/rules/` if present.
- You want a conservative plan with scope and risk level.

**Defer to `/gw-riskcheck`** if the work implies production impact, destructive actions, or sensitive data/auth changes — triage should not propose executing those without a risk pass.

**Full spec:** [`commands/gw-triage.md`](../../commands/gw-triage.md)

---

### `/gw-riskcheck`

**In plain terms:** “Are we allowed to do this safely?”

**Use when:**

- Production, security, compliance, or data/schema changes.
- Migrations, RBAC, auth, tenancy, bulk updates.
- You need CAB review, rollback plan, and mitigations documented.

**Full spec:** [`commands/gw-riskcheck.md`](../../commands/gw-riskcheck.md)

---

### `/gw-review`

**In plain terms:** “Is this change set good to merge?”

**Use when:**

- Before PR or merge to `main`.
- You have a diff or list of files to check.
- You want Approve / Request Changes / Block with safety and module-boundary checks.

**Full spec:** [`commands/gw-review.md`](../../commands/gw-review.md)

---

### `/gw-handoff`

**In plain terms:** “What happened this session?” — for you or a human reviewer.

**Use when:**

- End of a Cursor session; you want a structured summary.
- You need: files changed, test status, next steps, open questions.

**Not the same as `/session-end`:** `/session-end` **writes** `.cursor/memory/HANDOFF.md` (Agent mode). `/gw-handoff` is an in-chat summary; pair with `/session-end` when closing a governed session.

**Full spec:** [`commands/gw-handoff.md`](../../commands/gw-handoff.md)

---

## How `/gw` fits the wider KSS session

| Phase | Commands |
|-------|----------|
| Session start | `/boot` |
| Governance | `/gw-triage`, `/gw-riskcheck`, `/gw-review`, `/gw-handoff` |
| Git | `/git-prepare` → `/git-save` → `/git-finalize` |
| Persist handoff / docs | `/session-end`, `/docpack` |
| Architecture record | `/adr` |

`/boot` may suggest `/gw-triage` when work touches database, tenancy, security, hosting, or might require a new module.

See also: [KSS Handbook](../handbook.md) — Commands and Ready-to-Use Prompts.

---

## Related resources

| Resource | Purpose |
|----------|---------|
| [`safe-coding-practices`](../../../skills/safe-coding-practices/SKILL.md) skill | Evidence-based changes, rollback |
| Project `.cursor/rules/` (if present) | Project-specific boundary/placement rules (paired with `/gw-triage`) |
| [ADR command guide](adr-command.md) | `/adr` for architectural decisions |
| [Boot commands paths](boot-commands-paths.md) | Canonical `.cursor/memory/` paths |

---

## Ready-to-use prompts

| Scenario | Prompt |
|----------|--------|
| Triage unclear request | `/gw-triage` then describe the request |
| Assess risk before change | `/gw-riskcheck` then describe the change |
| Review before merge | `/gw-review` then paste diff or describe changes |
| Session summary | `/gw-handoff` |
