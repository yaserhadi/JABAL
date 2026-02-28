# Triage

**Purpose**: Ask clarifying questions only if necessary, otherwise propose the safest default plan.

**Related Commands**: `/gw-riskcheck`, `/gw-handoff`, `/gw-review`

---

**Workflow**:
1. Analyze the user's request for clarity and completeness
2. If the request implies production impact or destructive actions, do not propose a plan; defer to `/gw-riskcheck`
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

## Notes

- This command complements the `safe-coding-practices` skill
- When in doubt, prefer safety over speed
- Always cite evidence for findings
- Stop and ask if context is missing
