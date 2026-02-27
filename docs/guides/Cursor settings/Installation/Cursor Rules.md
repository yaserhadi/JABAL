## Cursor Rules Installation Instructions

**To:** All Development Team Members  
**Purpose:** Install organizational rules that will automatically guide AI behavior in all projects

### What You're Installing

You'll install **1 global rule** that applies to all your Cursor sessions:
- `plans-location` - Ensures plans are saved to project directories (not global directory)

### Installation Steps

**Step 1: Create the rules directory**

Open your terminal/PowerShell and run:

**Windows (PowerShell):**
```powershell
if (-not (Test-Path "$env:USERPROFILE\.cursor\rules")) { mkdir "$env:USERPROFILE\.cursor\rules" }
```

**Mac/Linux (Terminal):**
```bash
mkdir -p ~/.cursor/rules
```

**Step 2: Copy the rule file**

You will receive a file named `plans-location.mdc`. Copy it to:

- **Windows:** `C:\Users\[YourUsername]\.cursor\rules\plans-location.mdc`
- **Mac/Linux:** `~/.cursor/rules/plans-location.mdc`

Replace `[YourUsername]` with your actual Windows username.

**Step 3: Restart Cursor**

Close and restart Cursor to load the new rule.

### What This Rule Does

This rule automatically ensures that:
- All Cursor plans are created in your **project's `.cursor/plans/` folder**
- Plans stay with the project (tracked in version control)
- Team members can see project planning history
- Your workspace stays organized

**Before this rule:** Plans might be saved to `C:\Users\[YourUsername]\.cursor\plans\` (global)  
**After this rule:** Plans are saved to `[YourProject]\.cursor\plans\` (project-level)

### Verification

After installation:
1. Open any project in Cursor
2. Create a new plan (if you create one)
3. The plan will automatically be saved to `[YourProject]\.cursor\plans\`

The rule is working correctly if you see plans being created inside your project folders rather than in your user directory.

### Directory Structure After Installation

```
C:\Users\[YourUsername]\.cursor\
├── rules\
│   └── plans-location.mdc
```

### Need Help?

If you encounter any issues during installation, contact [your IT contact/team lead].

---

**Attached Files:**
- `plans-location.mdc` (rule file)

---

**Note:** This rule works automatically - you don't need to do anything special. Once installed, it applies to all your Cursor sessions across all projects.

---

This is ready to send to your employees!