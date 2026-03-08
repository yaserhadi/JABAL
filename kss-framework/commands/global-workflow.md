# Global Workflow Command

This command provides four workflow sub-commands for structured development tasks.

## Usage

Invoke with a sub-command:
- `/global-workflow triage` - Triage a request
- `/global-workflow riskcheck` - Assess risks and approvals
- `/global-workflow handoff` - Prepare handoff summary
- `/global-workflow review` - Review changes for compliance

---

## triage

**Purpose**: Ask clarifying questions only if necessary, otherwise propose the safest default plan.

**Workflow**:
1. Analyze the user's request for clarity and completeness
2. If the request implies production impact or destructive actions, do not propose a plan; defer to `/global-workflow riskcheck`
3. If request is clear and unambiguous (and low-risk):
   - Propose the safest, most conservative plan immediately
   - Prefer reversible over irreversible actions
   - Prefer additive over destructive changes
4. If request is ambiguous or missing critical context:
   - Ask 1-2 critical clarifying questions only
   - Do not ask obvious or unnecessary questions
   - Focus on: scope, environment, constraints, expected outcome

---

## riskcheck

**Purpose**: Identify security, compliance, and production risks. Flag required approvals.

**Workflow**:
1. Analyze the proposed change for risk categories:
   - **Security**: Auth, permissions, secrets, injection, XSS
   - **Compliance**: Audit trails, data retention, PII handling
   - **Production**: Live system impact, downtime, data loss
   - **Data**: Schema changes, migrations, bulk updates
2. Check against CAB governance rules (user rules)
3. Approvals must align with Global Rules (CAB, Production Safety, Data Protection)
4. Determine required approvals
5. Assess risk level using safeguard-based criteria

---

## handoff

**Purpose**: Summarize changes, file list, tests, and next steps for human review.

**Workflow**:
1. Collect all changes made in the current session
2. List files by action (added, edited, removed)
3. Summarize what changed and why
4. List verification steps and tests
5. Define next steps for human reviewer

---

## review

**Purpose**: Review a diff or change set for safety, security, and rule compliance.

**Workflow**:
1. Analyze the diff or change set
2. Check against safety rules
3. Check against security rules
4. Check against project/compliance rules

---

## Notes

- This command complements the `safe-coding-practices` skill
- When in doubt, prefer safety over speed
- Always cite evidence for findings
- Stop and ask if context is missing
