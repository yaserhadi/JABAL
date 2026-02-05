---
name: Create Global Workflow Command
overview: Implement the global-workflow command in the existing file at ~/.cursor/commands/global-workflow.md with four sub-commands (/triage, /riskcheck, /handoff, /review) for structured development workflow stages.
todos:
  - id: write-workflow-command
    content: Write global-workflow.md content with all four sub-command definitions and output templates
    status: completed
  - id: verify-workflow-command
    content: Verify command file is complete and complements safe-coding-practices skill
    status: completed
isProject: false
---

# Create Global Workflow Command

## Purpose

Implement the existing command file at `C:\Users\YH\.cursor\commands\global-workflow.md` with four workflow sub-commands:

| Sub-Command | Purpose |
|-------------|---------|
| `/triage` | Ask clarifying questions only if necessary, otherwise propose safest default plan |
| `/riskcheck` | Identify security/compliance/production risk and required approvals (CAB/prod) |
| `/handoff` | Summarize changes, file list, tests, and next steps for human review |
| `/review` | Review a diff or change set for safety, security, and rule compliance |

## File Location

```
C:\Users\YH\.cursor\commands\global-workflow.md  (already exists)
```

## Command vs Skill

- **Cursor Command** (`~/.cursor/commands/`): Invoked via `/command-name` - this is what you have
- **Cursor Skill** (`~/.cursor/skills/`): Auto-detected based on description keywords

Since you want explicit `/global-workflow` invocation, the Command file is the correct approach.

## Command Content Design

The command file will contain instructions that guide the agent when `/global-workflow` is invoked. The user can specify which sub-command to run:
- `/global-workflow triage`
- `/global-workflow riskcheck`
- `/global-workflow handoff`
- `/global-workflow review`

### Sub-Command Definitions

#### triage
- Check if request is clear enough to proceed
- If ambiguous: ask 1-2 critical questions only
- If clear: propose safest default plan immediately
- Prefer conservative/reversible approaches

#### riskcheck
- Identify risk category (security, compliance, production, data)
- Flag if CAB review required per user rules
- Flag if production approval needed
- Output: risk level + required approvals + mitigations

#### handoff
- List all files changed (add/edit/remove)
- Summarize what changed and why
- List tests to run
- Define next steps for human reviewer
- Format as reviewable bullet points

#### review
- Check diff against safety rules
- Check for security vulnerabilities
- Check compliance with project rules
- Output: findings categorized as critical/warning/info

## Implementation Steps

1. Write content to existing `~/.cursor/commands/global-workflow.md`
2. Include all four sub-commands with instructions and output templates
3. Verify it complements (not duplicates) safe-coding-practices skill

## Verification

- [ ] File written to correct location
- [ ] All four sub-commands defined with clear instructions
- [ ] Each sub-command has output template
- [ ] No overlap with safe-coding-practices (complementary)