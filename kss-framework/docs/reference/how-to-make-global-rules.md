# How to Create Global Cursor Rules

## Overview

Global rules apply to ALL your Cursor projects. Project-specific rules override global rules.

## Directory Structure

```
~/.cursor/
├── rules/
│   ├── reports-location-global.md     ← Global rule (all projects)
│   ├── plans-location.mdc             ← Another global rule
│   └── git-main-merge-only.mdc        ← Branch before edits on main
│
├── commands/
│   ├── en.md, ar.md, aren.md          ← Language overrides (not a rule file)
│   └── (other global commands)
│
├── agents/
│   └── (your global agents)
│
└── skills/
    └── (your global skills)
```

## Rule Priority (Highest to Lowest)

1. **Project-specific rule** → `.cursor/rules/[rule-name].md`
2. **Global rule** → `~/.cursor/rules/[rule-name].md`
3. **No rule** → Agent decides

**Example:**
- If a project has `.cursor/rules/reports-location.md` → Use project rule
- If a project has no rule → Use global rule from `~/.cursor/rules/`

---

## Creating New Global Rules

### Step 1: Create Rule File

```bash
# Create in your user Cursor directory
~/.cursor/rules/your-rule-name.md
```

### Step 2: Rule File Structure

```markdown
# Global Rule: [Rule Name]

## Scope: ALL PROJECTS

[Description of what the rule does]

## Rule: [Clear statement]

1. First requirement
2. Second requirement
3. Third requirement

## Why This Rule Exists

[Rationale]

## AI Agent Instructions

[Specific instructions for AI agents]

## Exclusions

[What this rule doesn't apply to]

---

**Rule Type**: Global (All Projects)
**Created**: YYYY-MM-DD
**Status**: Active
```

### Step 3: Test the Rule

Open any Cursor project and ask the agent to perform an action covered by your rule. The agent should follow the global rule automatically.

---

## Examples of Useful Global Rules

### 1. Reports Location
- **File:** `~/.cursor/rules/reports-location-global.md`
- **Purpose:** All reports go in `.cursor/reports/`

### 2. Plans Location
- **File:** `~/.cursor/rules/plans-location.mdc`
- **Purpose:** All work plans go in `.cursor/plans/`

### 3. Git Commit Standards
- **File:** `~/.cursor/rules/git-commit-standards-global.md`
- **Purpose:** Conventional commits, no direct commits to main

### 4. Documentation Structure
- **File:** `~/.cursor/rules/docs-structure-global.md`
- **Purpose:** Enforce consistent `docs/` folder organization

---

## How to Manage Global Rules

### View All Global Rules

```bash
ls ~/.cursor/rules/
```

### Edit a Global Rule

Just open and edit the file in your favorite editor.

### Disable a Global Rule (Temporarily)

Rename the file with `.disabled` extension:

```bash
mv ~/.cursor/rules/reports-location-global.md ~/.cursor/rules/reports-location-global.md.disabled
```

### Re-enable

Remove the `.disabled` extension:

```bash
mv ~/.cursor/rules/reports-location-global.md.disabled ~/.cursor/rules/reports-location-global.md
```

---

## Project-Specific Override

If a specific project needs different rules:

### Step 1: Create Project Rule

```bash
# In your project
.cursor/rules/reports-location.md
```

### Step 2: Override the Global Rule

```markdown
# Project Override: Custom Report Location

This project stores reports in a different location than the global rule.

## Rule: Reports in `project-docs/reports/`

[Custom instructions for this project]

---

**Overrides**: Global rule `reports-location-global.md`
```

The project-specific rule will take precedence over the global rule for that project only.

---

## Best Practices

### DO:
- Create global rules for standards you want across ALL projects
- Use clear, specific rule names (`reports-location-global.md`)
- Document the rationale for each rule
- Include AI agent instructions
- Test rules in multiple projects

### DON'T:
- Create project-specific rules in global folder
- Use vague rule names (`rule1.md`, `my-rule.md`)
- Create conflicting global rules
- Forget to document why a rule exists

---

**Purpose**: Guide for managing global Cursor rules
**Audience**: KSS adopters
