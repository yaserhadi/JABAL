## Cursor Subagents & Commands Installation Instructions

**To:** All Development Team Members  
**Purpose:** Install documentation stewardship subagents and workflow commands

### What You're Installing

You'll install **2 subagents** and **2 commands** that help maintain project documentation:

**Subagents (AI assistants):**
- `ai-knowledge-steward` - Maintains engineering memory for AI continuity
- `user-doc-steward` - Maintains user-facing documentation

**Commands (workflows):**
- `/boot` - Start sessions with proper context loading
- `/docpack` - Update documentation after work is done

### Installation Steps

**Step 1: Create the directories**

Open your terminal/PowerShell and run:

**Windows (PowerShell):**
```powershell
if (-not (Test-Path "$env:USERPROFILE\.cursor\agents")) { mkdir "$env:USERPROFILE\.cursor\agents" }
if (-not (Test-Path "$env:USERPROFILE\.cursor\commands")) { mkdir "$env:USERPROFILE\.cursor\commands" }
```

**Mac/Linux (Terminal):**
```bash
mkdir -p ~/.cursor/agents
mkdir -p ~/.cursor/commands
```

**Step 2: Copy the subagent files**

You will receive 2 files. Copy them to the agents directory:

**Windows:**
- Copy `ai-knowledge-steward.md` to:  
  `C:\Users\[YourUsername]\.cursor\agents\ai-knowledge-steward.md`
- Copy `user-doc-steward.md` to:  
  `C:\Users\[YourUsername]\.cursor\agents\user-doc-steward.md`

**Mac/Linux:**
- Copy `ai-knowledge-steward.md` to:  
  `~/.cursor/agents/ai-knowledge-steward.md`
- Copy `user-doc-steward.md` to:  
  `~/.cursor/agents/user-doc-steward.md`

**Step 3: Copy the command files**

You will receive 2 command files. Copy them to the commands directory:

**Windows:**
- Copy `boot.md` to:  
  `C:\Users\[YourUsername]\.cursor\commands\boot.md`
- Copy `docpack.md` to:  
  `C:\Users\[YourUsername]\.cursor\commands\docpack.md`

**Mac/Linux:**
- Copy `boot.md` to:  
  `~/.cursor/commands/boot.md`
- Copy `docpack.md` to:  
  `~/.cursor/commands/docpack.md`

Replace `[YourUsername]` with your actual Windows username.

**Step 4: Restart Cursor**

Close and restart Cursor to load the new subagents and commands.

### What These Tools Do

**`/boot` command:**
- Loads project context at the start of each session
- Reads project state, last session summary, and documentation mode
- Helps you start work with full context immediately

**`/docpack` command:**
- Updates documentation at the end of your work session
- Proposes updates based on what you changed
- Invokes the stewards to maintain both technical and user documentation

**ai-knowledge-steward:**
- Maintains technical documentation (architecture, decisions, lessons)
- Writes in `memory/` directory
- Triggered automatically after important decisions or changes

**user-doc-steward:**
- Maintains user-facing documentation (guides, FAQ, release notes)
- Writes in `docs/` directory
- Triggered when user-facing features are completed

### Verification

After installation, verify by:

1. Open any project in Cursor
2. Type `/boot` in the chat - you should see the command available
3. Type `/docpack` in the chat - you should see the command available
4. The subagents work in the background automatically (you'll see them invoked by commands)

### How to Use

**Starting your work session:**
```
/boot
```
This loads project context and tells you where you are and what's next.

**Ending your work session:**
```
/docpack
```
This proposes documentation updates based on what you changed, then applies after your confirmation.

### Directory Structure After Installation

```
C:\Users\[YourUsername]\.cursor\
├── agents\
│   ├── ai-knowledge-steward.md
│   └── user-doc-steward.md
└── commands\
    ├── boot.md
    └── docpack.md
```

### Need Help?

If you encounter any issues during installation, contact [your IT contact/team lead].

---

**Attached Files:**
- `ai-knowledge-steward.md` (subagent file)
- `user-doc-steward.md` (subagent file)
- `boot.md` (command file)
- `docpack.md` (command file)

---

**Note:** These tools work automatically with the rules and skills you've already installed. Together, they form the complete Knowledge Stewardship System.
