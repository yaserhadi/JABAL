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

**Output Format**:
```
## Triage Result

**Request clarity**: Clear / Needs clarification

**Questions** (if any):
1. [Critical question]
2. [Critical question]

**Proposed Plan** (if clear):
- Approach: [safest default approach]
- Scope: [what will be changed]
- Risk level: Low / Medium / High
- Reversible: Yes / No

**Evidence Referenced**:
- [files/commands/tests that support the conclusions]
```

---

## riskcheck

**Purpose**: Identify security, compliance, and production risks. Flag required approvals.

**Workflow**:
1. Analyze the proposed change for risk categories:
   - **Security**: Auth, permissions, secrets, injection, XSS
   - **Compliance**: Audit trails, data retention, PII handling
   - **Production**: Live system impact, downtime, data loss
   - **Data**: Schema changes, migrations, bulk updates
   - When assessing compliance risks, refer to "applicable regulatory and internal compliance requirements" rather than assuming specific frameworks (SOX, PCI-DSS, etc.) unless explicitly mentioned by the user.
2. Check against CAB governance rules (user rules)
3. Approvals must align with Global Rules (CAB, Production Safety, Data Protection)
4. Determine required approvals
5. Assess risk level using safeguard-based criteria:
   - **Critical**: Rollback is untested OR production backup/recovery cannot be confirmed OR no staging environment available OR safeguards are absent/uncertain
   - **High**: Rollback plan exists and tested, backup available, batching and monitoring in place, but significant production impact expected
   - **Medium**: Rollback plan exists, limited production impact, staging available, safeguards confirmed
   - **Low**: Minimal production impact, well-tested rollback, staging environment available, all safeguards in place
   - Base the level on presence/absence of safeguards (backup, rollback, staging, monitoring) and blast radius, not just on change sensitivity.

**Risk Triggers Requiring CAB Review**:
- Production-impacting changes
- Security-sensitive changes
- Data-affecting changes (schema, migrations, bulk updates)
- Access-control or permission changes
- High-risk or irreversible changes
- Cross-module or system-wide changes

**Output Format**:
```
## Risk Assessment

**Risk Categories Identified**:
- [ ] Security
- [ ] Compliance
- [ ] Production
- [ ] Data

**Risk Level**: Low / Medium / High / Critical
**Risk Level Rationale**: [Brief explanation based on safeguards (backup/rollback/staging), blast radius, and reversibility]

**Assumptions** (if any):
- [What was inferred vs explicitly stated by the user]
- [Any assumptions about scope, environment, or impact]

**CAB Review Required**: Yes / No
**Reason**: [why CAB is or isn't required]

**Production Approval Required**: Yes / No

**Identified Risks**:
1. [Risk description] - Severity: [Low/Medium/High]
2. [Risk description] - Severity: [Low/Medium/High]

**Mitigations**:
1. [Mitigation for risk 1]
2. [Mitigation for risk 2]

**Rollback Plan**:
- [How to revert if something goes wrong]

**Evidence Referenced**:
- [files/commands/tests that support the conclusions]
```

---

## handoff

**Purpose**: Summarize changes, file list, tests, and next steps for human review.

**Workflow**:
1. Collect all changes made in the current session
2. List files by action (added, edited, removed)
3. Summarize what changed and why
4. List verification steps and tests
5. Define next steps for human reviewer

**Output Format**:
```
## Handoff Summary

**Session Summary**:
[1-2 sentence overview of what was accomplished]

**Files Changed**:

| Action | File | Description |
|--------|------|-------------|
| Add | path/to/file.ext | [what was added] |
| Edit | path/to/file.ext | [what was changed] |
| Remove | path/to/file.ext | [why removed] |

**What Changed and Why**:
- [Change 1]: [reason]
- [Change 2]: [reason]
- [Change 3]: [reason]

**Tests to Run**:
- [ ] [Test command or step 1]
- [ ] [Test command or step 2]
- [ ] [Manual verification step]

**Next Steps for Reviewer**:
1. [Action item 1]
2. [Action item 2]
3. [Action item 3]

**Open Questions / Decisions Needed**:
- [Any unresolved items requiring human decision]

**Evidence Referenced**:
- [files/commands/tests that support the conclusions]
```

---

## review

**Purpose**: Review a diff or change set for safety, security, and rule compliance.

**Workflow**:
1. Analyze the diff or change set
2. Check against safety rules:
   - No hardcoded secrets or credentials
   - No destructive actions without approval
   - No production changes without explicit approval
3. Check against security rules:
   - Input validation present
   - No SQL injection vulnerabilities
   - No XSS vulnerabilities
   - Proper authentication/authorization
4. Check against project/compliance rules:
   - Follows modular boundaries
   - Least privilege applied
   - Audit trail maintained where required

**Output Format**:
```
## Change Review

**Scope**: [Brief description of what is being reviewed]

**Findings**:

### Critical (must fix before merge)
- [ ] [Finding description]

### Warning (should fix)
- [ ] [Finding description]

### Info (consider)
- [ ] [Finding description]

**Safety Check**:
- [ ] No hardcoded secrets
- [ ] No destructive actions without approval
- [ ] Rollback path exists

**Security Check**:
- [ ] Input validation present
- [ ] No injection vulnerabilities
- [ ] Auth/authz properly implemented

**Compliance Check**:
- [ ] Follows module boundaries
- [ ] Least privilege applied
- [ ] Audit requirements met

**Verdict**: Approve / Request Changes / Block

**Summary**:
[1-2 sentence summary of review findings]

**Evidence Referenced**:
- [files/commands/tests that support the conclusions]
```

---

## Notes

- This command complements the `safe-coding-practices` skill
- When in doubt, prefer safety over speed
- Always cite evidence for findings
- Stop and ask if context is missing
