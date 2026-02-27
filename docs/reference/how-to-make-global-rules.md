# How to Create Global Cursor Rules

## What Just Happened

We created **TWO rules** for report storage:

### 1. Project-Specific Rule ✅
**Location:** `.cursor/rules/reports-location.md`  
**Scope:** This project (Jabal) only  
**Purpose:** Enforces report location for this specific project

### 2. Global Rule ✅
**Location:** `C:\Users\YH\.cursor\rules\reports-location-global.md`  
**Scope:** ALL your Cursor projects  
**Purpose:** Default rule for all future projects

---

## How Global Rules Work

### Directory Structure

```
C:\Users\YH\.cursor\
├── rules\
│   ├── reports-location-global.md     ← Global rule (all projects)
│   ├── safe-coding-global.md          ← Another global rule example
│   └── git-safety-global.md           ← Another global rule example
│
└── skills\
    └── (your global skills)
```

### Rule Priority (Highest to Lowest)

1. **Project-specific rule** → `.cursor/rules/reports-location.md`
2. **Global rule** → `C:\Users\YH\.cursor\rules\reports-location-global.md`
3. **No rule** → Agent decides

**Example:**
- If Jabal project has `.cursor/rules/reports-location.md` → Use project rule
- If another project has no rule → Use global rule from `C:\Users\YH\.cursor\rules/`

---

## Creating New Global Rules

### Step 1: Create Rule File

```bash
# Create in your user Cursor directory
C:\Users\YH\.cursor\rules\your-rule-name.md
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

### 1. Reports Location (Already Created ✅)
- **File:** `C:\Users\YH\.cursor\rules\reports-location-global.md`
- **Purpose:** All reports go in `.cursor/reports/`

### 2. Plans Location
- **File:** `C:\Users\YH\.cursor\rules\plans-location-global.md`
- **Purpose:** All work plans go in `.cursor/plans/`

### 3. Git Commit Standards
- **File:** `C:\Users\YH\.cursor\rules\git-commit-standards-global.md`
- **Purpose:** Conventional commits, no direct commits to main

### 4. Documentation Structure
- **File:** `C:\Users\YH\.cursor\rules\docs-structure-global.md`
- **Purpose:** Enforce consistent `docs/` folder organization

### 5. Test Organization
- **File:** `C:\Users\YH\.cursor\rules\test-organization-global.md`
- **Purpose:** Enforce test folder structure and naming

---

## How to Manage Global Rules

### View All Global Rules
```powershell
Get-ChildItem C:\Users\YH\.cursor\rules\
```

### Edit a Global Rule
Just open and edit the file in your favorite editor:
```powershell
code C:\Users\YH\.cursor\rules\reports-location-global.md
```

### Disable a Global Rule (Temporarily)
Rename the file with `.disabled` extension:
```powershell
Rename-Item "C:\Users\YH\.cursor\rules\reports-location-global.md" `
            "C:\Users\YH\.cursor\rules\reports-location-global.md.disabled"
```

### Re-enable
Remove the `.disabled` extension:
```powershell
Rename-Item "C:\Users\YH\.cursor\rules\reports-location-global.md.disabled" `
            "C:\Users\YH\.cursor\rules\reports-location-global.md"
```

### Delete a Global Rule
Simply delete the file (affects all future projects):
```powershell
Remove-Item C:\Users\YH\.cursor\rules\reports-location-global.md
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

## Checking Which Rules Apply

### In Any Project, Ask the Agent:
```
"Show me which report storage rules apply to this project"
```

The agent will check:
1. `.cursor/rules/reports-location.md` (project-specific)
2. `C:\Users\YH\.cursor\rules\reports-location-global.md` (global)

---

## Best Practices

### ✅ DO:
- Create global rules for standards you want across ALL projects
- Use clear, specific rule names (`reports-location-global.md`)
- Document the rationale for each rule
- Include AI agent instructions
- Test rules in multiple projects

### ❌ DON'T:
- Create project-specific rules in global folder
- Use vague rule names (`rule1.md`, `my-rule.md`)
- Create conflicting global rules
- Forget to document why a rule exists

---

## Summary

**You now have:**

1. ✅ Project rule: `.cursor/rules/reports-location.md` (Jabal only)
2. ✅ Global rule: `C:\Users\YH\.cursor\rules\reports-location-global.md` (all projects)
3. ✅ Reports folder: `.cursor/reports/` (created)
4. ✅ Report moved: `PHASE1_CLOSURE_REPORT.md` (in correct location)

**All future projects** will automatically follow the reports location rule unless overridden!

---

**Created**: 2026-02-05  
**Purpose**: Guide for managing global Cursor rules  
**Audience**: You (project owner)
