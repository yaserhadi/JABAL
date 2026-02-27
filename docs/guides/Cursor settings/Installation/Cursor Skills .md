
## Cursor Skills Installation Instructions

**To:** All Development Team Members  
**Purpose:** Install coding practice skills that will automatically guide AI agents

### What You're Installing

You'll install **1 skill** that enforces safe coding practices:
- `safe-coding-practices` - Evidence-based development and safe change control

### Installation Steps

**Step 1: Create the skills directory structure**

Open your terminal/PowerShell and run:

**Windows (PowerShell):**
```powershell
if (-not (Test-Path "$env:USERPROFILE\.cursor\skills")) { mkdir "$env:USERPROFILE\.cursor\skills" }
if (-not (Test-Path "$env:USERPROFILE\.cursor\skills\safe-coding-practices")) { mkdir "$env:USERPROFILE\.cursor\skills\safe-coding-practices" }
```

**Mac/Linux (Terminal):**
```bash
mkdir -p ~/.cursor/skills/safe-coding-practices
```

**Step 2: Copy the skill file**

You will receive a file named `SKILL.md` (for safe-coding-practices). Copy it to:

- **Windows:** `C:\Users\[YourUsername]\.cursor\skills\safe-coding-practices\SKILL.md`
- **Mac/Linux:** `~/.cursor/skills/safe-coding-practices/SKILL.md`

Replace `[YourUsername]` with your actual Windows username.

**Step 3: Restart Cursor**

Close and restart Cursor to load the new skill.

### How This Skill Works

The `safe-coding-practices` skill will automatically activate when you're working on:
- Refactoring code
- Bug fixes
- Database migrations
- Security-sensitive changes (auth, permissions, RBAC)
- Production-impacting work
- Code reviews

The AI will automatically:
- Ask for evidence before making conclusions
- Propose minimal, safe changes
- Provide rollback strategies
- Give you verification checklists

### Verification

After installation, the skill is working if:
1. When you ask for code changes, the AI provides structured output with:
   - Evidence
   - Scope & ownership
   - Verification steps
   - Rollback strategy

### Directory Structure After Installation

```
C:\Users\[YourUsername]\.cursor\
├── skills\
│   └── safe-coding-practices\
│       └── SKILL.md
```

### Need Help?

If you encounter any issues during installation, contact [your IT contact/team lead].

---

**Attached Files:**
- `safe-coding-practices/SKILL.md` (skill file)

---

**Note:** This skill works alongside the subagents and commands you installed earlier. They complement each other:
- **Skills** = Automatic behaviors triggered by keywords
- **Subagents** = Specialized documentation maintainers
- **Commands** = Explicit workflows you invoke (/boot, /docpack)