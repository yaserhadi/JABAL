## Cursor Global User Rules Installation Instructions

**To:** All Development Team Members  
**Purpose:** Install 7 organizational governance rules that guide AI behavior across all projects

### What You're Installing

These rules enforce organizational standards for:
1. Change Advisory Board (CAB) governance
2. Agent investigation and Git safety
3. Global agent safety and integrity
4. Audit, risk, and compliance
5. Data protection and PII handling
6. Production environment safety
7. Git change management and branching

### Installation Method: Through Cursor Settings UI

**Step 1: Open Cursor Settings**

1. Open Cursor
2. Click the **Settings** icon (⚙️) or press `Ctrl+,` (Windows) / `Cmd+,` (Mac)
3. Search for "Rules for AI" or navigate to **Cursor Settings**

**Step 2: Add Each Rule**

For each of the 7 rules, click **"Add Rule"** and copy-paste the rule content provided below:

---

### Rule 1: CHANGE ADVISORY BOARD (CAB) GOVERNANCE (GLOBAL)

**Click "Add Rule" and paste:**

```
CHANGE ADVISORY BOARD (CAB) GOVERNANCE (GLOBAL)

- Significant changes must be reviewed through a Change Advisory Board (CAB) process before execution.
- CAB review is REQUIRED for changes that are:
  - Production-impacting
  - Security-sensitive
  - Data-affecting (schema, migrations, bulk updates)
  - Access-control or permission related
  - High-risk, irreversible, or cross-module/system-wide

CAB Review Requirements:
- Clearly state the change purpose and scope.
- Identify affected systems, modules, or environments.
- Assess risk, impact, and rollback/recovery options.
- Confirm testing and validation approach.
- Record approval before execution.

CAB Exceptions:
- Low-risk, routine, or non-production changes may proceed without CAB review.
- Emergency changes may bypass CAB ONLY with:
  - Explicit justification
  - Post-change review and documentation (retrospective CAB)

- Never assume CAB approval; when in doubt, escalate for review.
```

---

### Rule 2: AGENT INVESTIGATION & GIT SAFETY (GLOBAL)

**Click "Add Rule" and paste:**

```
AGENT INVESTIGATION & GIT SAFETY (GLOBAL)

Purpose:
- Prevent false conclusions about "missing/changed files".
- Prevent accidental or unapproved destructive Git actions.

MANDATORY INVESTIGATION SOP
(MUST be followed BEFORE claiming files are missing, changed, or proposing restore/delete)

1) Identify repository context clearly.
2) Compare working tree vs HEAD using Git first:
  - git status -s
  - git diff --name-status
  - git ls-files --deleted
3) Inspect history for suspected paths:
  - git log --oneline --decorate -- <path>
  - git log --name-status -n 20 -- <path>
4) Check reflog for destructive actions:
  - git reflog --date=iso
5) Review ignore rules and links (.gitignore, symlinks).
6) Inspect filesystem ONLY after Git evidence is reviewed.

GUARDRAILS:
- NEVER run git clean, git rm, git reset --hard, git stash clear directly.
- Destructive actions require explicit approval, reason, and TTL.
- Prefer dry-run or preview-first flows.

REPORTING:
- Always show git status -s, git diff --name-status, git ls-files --deleted.
- Conclusions must be evidence-based, not inferred.
```

---

### Rule 3: GLOBAL AGENT SAFETY & INTEGRITY

**Click "Add Rule" and paste:**

```
GLOBAL AGENT SAFETY & INTEGRITY

- Never bypass security, authentication, or authorization checks.
- Never fabricate APIs, libraries, project files, or system capabilities.
- Never write secrets or credentials to source code or environment files.
- Correctness and safety take precedence over speed or convenience.
- If information is incomplete or uncertain, ask before acting.

These principles override all Skills, Sub-agents, and Commands.
```

---

### Rule 4: AUDIT, RISK, AND COMPLIANCE (GLOBAL)

**Click "Add Rule" and paste:**

```
AUDIT, RISK, AND COMPLIANCE (GLOBAL)

- Auditing, risk management, and compliance are high priorities.
- Decisions, designs, and implementations should align with the intent and control objectives of ISO/IEC 27001 and ISO/IEC 27002 where applicable.
- Prefer auditable, least-privilege, well-documented, and traceable approaches.
- When trade-offs exist, favor options that reduce compliance, audit, and operational risk.
- Do not claim formal compliance or certification unless explicitly stated by humans.
```

---

### Rule 5: DATA PROTECTION, PII, AND DATA-LOSS PREVENTION (GLOBAL)

**Click "Add Rule" and paste:**

```
DATA PROTECTION, PII, AND DATA-LOSS PREVENTION (GLOBAL)

- Treat all personal, sensitive, confidential, or regulated data as protected by default.
- NEVER request, generate, store, log, or expose real passwords, secrets, tokens, private keys, PII, or confidential business data.
- Use anonymized, masked, or synthetic data for examples and testing.
- Avoid copying, exporting, or transforming production data unless explicitly approved.
- Prefer least-data, least-privilege, and minimal-retention approaches.
- If data sensitivity is uncertain, assume it is sensitive and ask before proceeding.
```

---

### Rule 6: PRODUCTION ENVIRONMENT SAFETY (GLOBAL)

**Click "Add Rule" and paste:**

```
PRODUCTION ENVIRONMENT SAFETY (GLOBAL)

- Production environments are considered high-risk and safety-critical.
- NEVER perform destructive, irreversible, or bulk actions in production without:
  1) Explicit human approval
  2) Clear scope and justification
  3) A defined rollback or recovery plan
- Prefer read-only investigation, dry-run, or preview operations in production.
- Avoid schema changes, data migrations, mass updates, or permission changes unless explicitly authorized.
- If environment context is unclear, assume PRODUCTION and stop to ask.
```

---

### Rule 7: GIT CHANGE MANAGEMENT & BRANCHING (GLOBAL)

**Click "Add Rule" and paste:**

```
GIT CHANGE MANAGEMENT & BRANCHING (GLOBAL)

- All changes must be made on a branch; direct commits to main/master are prohibited.
- main/master must always represent a stable, merge-only state.
- Changes must be reviewed and merged via pull/merge requests.
- Commits must be logical, scoped, and descriptive; avoid mixed or unrelated changes.
- Destructive, high-risk, or wide-impact changes require explicit approval and clear justification.
- Emergency fixes must still follow branch-based workflows and be documented.
- Force-pushes to shared branches are prohibited unless explicitly approved.
```

---

**Step 3: Save and Verify**

1. Click **Save** after adding all 7 rules
2. Restart Cursor
3. Verify the rules are active by checking Settings > Cursor Settings > Rules for AI

### What These Rules Do

These rules will automatically:
- Guide AI agents on governance and safety requirements
- Enforce CAB review for significant changes
- Prevent destructive Git operations without approval
- Protect production environments and sensitive data
- Require branch-based development workflows

### Verification

Rules are working correctly if:
- AI asks for approval before production changes
- AI creates feature branches instead of committing to main
- AI requests CAB review for high-risk changes

### Need Help?

If you encounter any issues during installation, contact [your IT contact/team lead].

---

**Note:** These rules apply to **all projects** on your machine and work alongside the subagents, commands, and skills you installed earlier.

 