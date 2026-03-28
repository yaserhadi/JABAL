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
5. **Module Creation Gate (`MODULE-CREATION-GATE`) — mandatory**:
   - For any feature or structural change (not pure docs/read-only), decide: **existing module** vs **`app/` kernel** vs **new module**.
   - Ask: Does this add a **tenant-facing surface** (routes, controllers, API, UI), **independent domain behavior**, or a **named capability**? If yes to a distinct capability, a **new module** may be required; if it extends an existing capability, **extend that module**; if it is cross-cutting only, keep it in **`app/`**.
   - Follow `kss-framework/rules/module-creation-gate.mdc` (copy to `.cursor/rules/` per `kss-framework/INSTALL.md` if using workspace rules) and ADR-0003. **Triaged first, confirmed at plan start** — if scope grows into a full feature surface, re-run this check.
6. **Architectural Decision Check**:
   - Does this change involve a choice between alternatives?
   - Does it establish a new pattern, API contract, or module boundary?
   - Does it have long-term architectural implications?
   - Does it affect security boundaries or data access rules?
   - Is there an existing ADR that covers this decision?

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

**Module boundary check (`MODULE-CREATION-GATE`)**:
- **Destination**: Existing module (name) / `app/` kernel / New module
- **Rationale**: [one or two sentences — why this destination fits]

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
- **`MODULE-CREATION-GATE`** is mandatory for feature work; full criteria: `kss-framework/rules/module-creation-gate.mdc`; architecture context: ADR-0003
- When in doubt, prefer safety over speed
- Always cite evidence for findings
- Stop and ask if context is missing
