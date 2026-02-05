---
name: Enhance Riskcheck Command Precision
overview: Enhance the riskcheck command to avoid regulatory assumptions, separate facts from assumptions, and provide nuanced risk level criteria. This prevents over-conservatism while maintaining safety.
todos:
  - id: add-regulatory-guidance
    content: Add guidance to avoid specific regulatory framework assumptions in riskcheck workflow
    status: completed
  - id: add-risk-level-criteria
    content: Add risk level determination criteria (especially High vs Critical distinction)
    status: completed
  - id: add-assumptions-section
    content: Add 'Assumptions' section to riskcheck output template
    status: completed
  - id: add-risk-rationale
    content: Add 'Risk Level Rationale' to riskcheck output template
    status: completed
isProject: false
---

# Enhance Riskcheck Command Precision

## Analysis of Feedback

The feedback identifies three valid improvements:

1. **Avoid Regulatory Assumptions**: Agent should not assume specific frameworks (SOX, PCI-DSS) unless explicitly mentioned
2. **Separate Facts from Assumptions**: Add explicit "Assumptions" section to distinguish what was stated vs inferred
3. **Nuanced Risk Levels**: Provide criteria to distinguish High vs Critical based on safeguards, not just impact

## Changes to Implement

### 1. Riskcheck Workflow: Prevent Regulatory Framework Assumptions

**Location**: [`C:\Users\YH\.cursor\commands\global-workflow.md`](C:\Users\YH\.cursor\commands\global-workflow.md) - riskcheck Workflow section (after step 1)

**Change**: Add guidance after step 1:

```
1. Analyze the proposed change for risk categories:
                                                                                 - **Security**: Auth, permissions, secrets, injection, XSS
                                                                                 - **Compliance**: Audit trails, data retention, PII handling
                                                                                 - **Production**: Live system impact, downtime, data loss
                                                                                 - **Data**: Schema changes, migrations, bulk updates
                                                                                 - When assessing compliance risks, refer to "applicable regulatory and internal compliance requirements" rather than assuming specific frameworks (SOX, PCI-DSS, etc.) unless explicitly mentioned by the user.
```

### 2. Riskcheck Workflow: Add Safeguard-Based Risk Level Criteria

**Location**: After step 4 in riskcheck Workflow section

**Change**: Add step 5 with safeguard-based criteria:

```
4. Determine required approvals
5. Assess risk level using safeguard-based criteria:
                                                                                 - **Critical**: Rollback is untested OR production backup/recovery cannot be confirmed OR no staging environment available OR safeguards are absent/uncertain
                                                                                 - **High**: Rollback plan exists and tested, backup available, batching and monitoring in place, but significant production impact expected
                                                                                 - **Medium**: Rollback plan exists, limited production impact, staging available, safeguards confirmed
                                                                                 - **Low**: Minimal production impact, well-tested rollback, staging environment available, all safeguards in place
                                                                                 - Base the level on presence/absence of safeguards (backup, rollback, staging, monitoring) and blast radius, not just on change sensitivity.
```

### 3. Riskcheck Output Template: Add Assumptions Section

**Location**: [`C:\Users\YH\.cursor\commands\global-workflow.md`](C:\Users\YH\.cursor\commands\global-workflow.md) - riskcheck Output Format (after Risk Level Rationale, before CAB Review)

**Change**: Insert new section after Risk Level Rationale:

```
**Risk Level**: Low / Medium / High / Critical
**Risk Level Rationale**: [Brief explanation based on safeguards (backup/rollback/staging), blast radius, and reversibility]

**Assumptions** (if any):
- [What was inferred vs explicitly stated by the user]
- [Any assumptions about scope, environment, or impact]

**CAB Review Required**: Yes / No
```

### 4. Riskcheck Output Template: Add Risk Level Rationale

**Location**: [`C:\Users\YH\.cursor\commands\global-workflow.md`](C:\Users\YH\.cursor\commands\global-workflow.md) - riskcheck Output Format (immediately after Risk Level line)

**Change**: Add rationale field:

```
**Risk Level**: Low / Medium / High / Critical
**Risk Level Rationale**: [Brief explanation of why this level was chosen based on safeguards/controls, blast radius, and reversibility]
```

### 5. Mitigations: Neutral Compliance Language (Optional Enhancement)

**Location**: In riskcheck Workflow section, add note about compliance language in mitigations

**Change**: Add guidance note:

```
When listing mitigations related to compliance, use neutral language:
- "Regulatory/compliance implications (as applicable to the organization's internal and regulatory requirements)"
- Avoid naming specific frameworks unless the user explicitly mentions them
```

## Implementation Details

### File to Modify

- `C:\Users\YH\.cursor\commands\global-workflow.md`

### Sections to Update

1. **riskcheck Workflow** (lines 57-65): 

                                                                                                                                                                                                - Add regulatory framework guidance after step 1
                                                                                                                                                                                                - Add safeguard-based risk level criteria as step 5
                                                                                                                                                                                                - Add optional mitigations compliance language guidance

2. **riskcheck Output Format** (lines 75-105): 

                                                                                                                                                                                                - Add "Risk Level Rationale" immediately after "Risk Level"
                                                                                                                                                                                                - Add "Assumptions" section after Risk Level Rationale, before CAB Review

### Expected Outcome

After these changes:

- Agent will avoid assuming specific regulatory frameworks
- Assumptions will be explicitly separated from facts
- Risk levels will be more nuanced (High vs Critical based on safeguards)
- Output will be more precise and less over-conservative

## Implementation Order

1. Update riskcheck Workflow section:

                                                                                                                                                                                                - Add regulatory guidance after step 1
                                                                                                                                                                                                - Add safeguard-based risk level criteria as step 5

2. Update riskcheck Output Format:

                                                                                                                                                                                                - Add "Risk Level Rationale" field after "Risk Level"
                                                                                                                                                                                                - Add "Assumptions" section after Risk Level Rationale

3. Keep structure minimal and consistent with existing style

## Verification

After implementation:

- [ ] Workflow includes guidance to avoid regulatory framework assumptions (unless explicitly mentioned)
- [ ] Safeguard-based risk level criteria added (distinguishes High vs Critical based on controls)
- [ ] Output template includes "Risk Level Rationale" explaining safeguards, blast radius, reversibility
- [ ] Output template includes "Assumptions" section separating inferred from stated facts
- [ ] Evidence Referenced section remains focused on actual evidence (user text, files, commands, results)
- [ ] Command maintains structural integrity and minimal style