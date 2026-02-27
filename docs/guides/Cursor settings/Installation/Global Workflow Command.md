
## Cursor Global Workflow Command Installation Instructions

**To:** All Development Team Members  
**Purpose:** Install the Global Workflow command for structured development workflows

### What You're Installing

You'll install **1 command** that provides 4 workflow sub-commands:
- `/global-workflow triage` - Triage requests with clarifying questions
- `/global-workflow riskcheck` - Assess security, compliance, and production risks
- `/global-workflow handoff` - Prepare handoff summaries for review
- `/global-workflow review` - Review changes for safety, security, and compliance

### Installation Steps

**Step 1: Create the commands directory (if not already done)**

Open your terminal/PowerShell and run:

**Windows (PowerShell):**
```powershell
if (-not (Test-Path "$env:USERPROFILE\.cursor\commands")) { mkdir "$env:USERPROFILE\.cursor\commands" }
```

**Mac/Linux (Terminal):**
```bash
mkdir -p ~/.cursor/commands
```

**Step 2: Copy the command file**

You will receive a file named `global-workflow.md`. Copy it to:

- **Windows:** `C:\Users\[YourUsername]\.cursor\commands\global-workflow.md`
- **Mac/Linux:** `~/.cursor/commands/global-workflow.md`

Replace `[YourUsername]` with your actual Windows username.

**Step 3: Restart Cursor**

Close and restart Cursor to load the new command.

### What This Command Does

The Global Workflow command provides structured workflows for common development tasks:

**`/global-workflow triage`**
- Analyzes your request for clarity
- Asks clarifying questions only if necessary
- Proposes the safest default plan
- Defers high-risk requests to riskcheck

**`/global-workflow riskcheck`**
- Identifies security, compliance, and production risks
- Determines if CAB (Change Advisory Board) review is required
- Assesses risk level (Low/Medium/High/Critical)
- Provides rollback plan

**`/global-workflow handoff`**
- Summarizes what was done in the session
- Lists all changed files
- Provides verification checklist
- Defines next steps for reviewer

**`/global-workflow review`**
- Reviews code changes for safety and security
- Checks for hardcoded secrets
- Validates input validation and auth/authz
- Ensures compliance with organizational rules

### How to Use

**Before making a risky change:**
```
/global-workflow riskcheck
```

**To triage a new request:**
```
/global-workflow triage
I want to [describe your task]
```

**Before ending your work session:**
```
/global-workflow handoff
```

**To review code changes:**
```
/global-workflow review
```

### Verification

After installation, verify by:

1. Open any project in Cursor
2. Type `/global-workflow` in the chat - you should see the command available
3. Type `/global-workflow triage` - the command should execute

### Directory Structure After Installation

```
C:\Users\[YourUsername]\.cursor\commands\
├── boot.md
├── docpack.md
└── global-workflow.md    ← NEW
```

### Integration with Other Tools

This command works alongside:
- **Safe Coding Practices skill** - Enforces evidence-based development
- **CAB Governance rules** - Validates change approval requirements
- **Production Safety rules** - Protects production environments

The workflow commands help you follow organizational governance automatically.

### Need Help?

If you encounter any issues during installation, contact [your IT contact/team lead].

---

**Attached Files:**
- `global-workflow.md` (command file)

---

**Note:** This is an optional but recommended command. It provides structured workflows for planning, risk assessment, handoffs, and code reviews.
