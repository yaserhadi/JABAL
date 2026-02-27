
## Cursor Plans Location Rule - Installation Instructions

**To:** All Development Team Members  
**Purpose:** Ensure all Cursor plans are saved to project directories (not global directory)

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

**Step 2: Create the rule file**

You will receive a file named `plans-location.mdc`. Copy it to:

- **Windows:** `C:\Users\[YourUsername]\.cursor\rules\plans-location.mdc`
- **Mac/Linux:** `~/.cursor/rules/plans-location.mdc`

Replace `[YourUsername]` with your actual Windows username.

**Step 3: Verify installation**

1. Restart Cursor if it's currently open
2. The rule is now active and will apply to all your Cursor sessions automatically

### What This Rule Does

From now on, all Cursor plans will be created in your project's `.cursor/plans/` folder instead of a global directory. This ensures:
- Plans are tracked with your project in version control
- Team members can see project planning history
- Project context stays organized

### Verification

After installation, when you create a new plan in Cursor, it will automatically be saved to:
```
[YourProject]\.cursor\plans\
```

Not to:
```
C:\Users\[YourUsername]\.cursor\plans\
```

### Need Help?

If you encounter any issues during installation, contact [your IT contact/team lead].

---

**Attached Files:**
- `plans-location.mdc` (rule file)

---