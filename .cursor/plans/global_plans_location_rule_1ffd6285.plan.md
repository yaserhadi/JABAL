---
name: Global Plans Location Rule
overview: Create a global Cursor rule that automatically enforces keeping all plans at project level (.cursor/plans/) instead of global directory
todos: []
isProject: false
---

# Create Global Cursor Rule for Plans Location

## Overview

Create a global Cursor rule (`.cursor/rules/`) that automatically reminds and enforces keeping all plans at the project level rather than the global plans directory. This is better than memory files because it's enforced by Cursor automatically across all sessions.

## Why This Approach is Better

1. **Automatic enforcement** - Cursor loads rules automatically, no need to read memory files
2. **Global scope** - Applies to all projects on this machine
3. **Proper Cursor pattern** - Uses the official rules system
4. **Cleaner** - Doesn't clutter project-specific memory files with general practices

## Implementation

### Create Global Rule

**File:** `~/.cursor/rules/plans-location.mdc`

**Location:** `C:\Users\YH\.cursor\rules\plans-location.mdc` (directory needs to be created)

**Content:**

```markdown
---
description: Ensures Cursor plans are saved to project-level .cursor/plans/ directory
alwaysApply: true
---

# Plans Must Stay at Project Level

## Rule
All Cursor plans MUST be saved to the project-level `.cursor/plans/` directory, NOT the global `~/.cursor/plans/` directory.

## Why
- **Version control**: Project plans should be tracked with the project
- **Context**: Plans provide valuable project history and decision context
- **Team collaboration**: Other team members need access to project plans
- **Organization**: Each project's plans stay with that project

## When Creating Plans
- Plans are automatically created in the project's `.cursor/plans/` folder
- If a plan is created in `~/.cursor/plans/`, move it immediately to the project folder

## Quick Reference
```powershell
# Move a plan from global to project (Windows)
Move-Item -Path "$env:USERPROFILE\.cursor\plans\[filename].plan.md" -Destination ".cursor\plans\[filename].plan.md"

# Move a plan from global to project (Mac/Linux)
mv ~/.cursor/plans/[filename].plan.md .cursor/plans/[filename].plan.md
```

## Enforcement

This is a non-negotiable organizational standard. All agents must follow this rule.

```

## Steps

1. **Create rules directory** (if it doesn't exist)

   - Create `C:\Users\YH\.cursor\rules\`

2. **Create rule file**

   - Create `plans-location.mdc` with the content above
   - Use `.mdc` extension (required by Cursor)
   - Set `alwaysApply: true` so it applies to all sessions

3. **Verification**

   - Rule will be automatically loaded by Cursor on next session
   - Can be verified by checking if rule appears in Cursor's context

## Expected Behavior

After this rule is created:

- Every Cursor session will have this rule loaded automatically
- AI agents will see this guidance before creating plans
- Plans will naturally be created in project directories
- If a plan ends up in global directory, it can be quickly moved using the provided commands

## No Memory File Changes Needed

This approach completely replaces the need to update `memory/AI_ENTRY.md` or `memory/LESSONS.md` because:

- The rule is more visible (always loaded)
- It's enforced globally (not just in this project)
- It's the proper Cursor pattern for persistent guidance
- It keeps project memory files focused on project-specific context

## Files Created

1. `~/.cursor/rules/` - New directory
2. `~/.cursor/rules/plans-location.mdc` - New rule file
```

