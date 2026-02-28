# Review

**Purpose**: Review a diff or change set for safety, security, and rule compliance.

**Related Commands**: `/gw-triage`, `/gw-riskcheck`, `/gw-handoff`

---

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
5. **Architectural Decision Check**:
   - Does this change involve a choice between alternatives?
   - Does it establish a new pattern, API contract, or module boundary?
   - Does it have long-term architectural implications?
   - Does it affect security boundaries or data access rules?
   - Is there an existing ADR that covers this decision?

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

**Architectural Decision Check**:
- [ ] Involves architectural choice: Yes / No
- [ ] Decision type: [DB/API/Security/Module/Integration/None]
- [ ] Existing ADR covers this: [ADR-XXXX] / None found / N/A
- [ ] ADR recommended: Yes / No

**If ADR Recommended**:
> Run `/adr` before proceeding. Paste the discussion context.
> Decision indicators detected: [list what triggered this]
```

**Decision Indicators** (triggers ADR recommendation):
- Trade-off language: "chose X over Y", "decided to", "alternative considered"
- New patterns: database schema, API endpoint design, authentication flow
- Breaking changes: contract modifications, interface changes
- Security boundaries: permission model, data access rules
- Module boundaries: new module, cross-module dependency

---

## Notes

- This command complements the `safe-coding-practices` skill
- When in doubt, prefer safety over speed
- Always cite evidence for findings
- Stop and ask if context is missing
