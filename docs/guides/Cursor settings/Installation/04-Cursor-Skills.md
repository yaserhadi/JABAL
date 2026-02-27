# 04 — Cursor Skills

**Purpose:** Install 4 skills for AI guidance.

## Skills to Copy

| Skill | Source | Destination |
|-------|--------|-------------|
| safe-coding-practices | skills/safe-coding-practices/ | ~/.cursor/skills/safe-coding-practices/SKILL.md |
| create-rule | skills/create-rule/ | ~/.cursor/skills-cursor/create-rule/SKILL.md |
| create-skill | skills/create-skill/ | ~/.cursor/skills-cursor/create-skill/SKILL.md |
| update-cursor-settings | skills/update-cursor-settings/ | ~/.cursor/skills-cursor/update-cursor-settings/SKILL.md |

## Windows

```powershell
mkdir -p $env:USERPROFILE\.cursor\skills\safe-coding-practices
mkdir -p $env:USERPROFILE\.cursor\skills-cursor\create-rule
mkdir -p $env:USERPROFILE\.cursor\skills-cursor\create-skill
mkdir -p $env:USERPROFILE\.cursor\skills-cursor\update-cursor-settings
Copy-Item ".\skills\safe-coding-practices\SKILL.md" "$env:USERPROFILE\.cursor\skills\safe-coding-practices\"
Copy-Item ".\skills\create-rule\SKILL.md" "$env:USERPROFILE\.cursor\skills-cursor\create-rule\"
Copy-Item ".\skills\create-skill\SKILL.md" "$env:USERPROFILE\.cursor\skills-cursor\create-skill\"
Copy-Item ".\skills\update-cursor-settings\SKILL.md" "$env:USERPROFILE\.cursor\skills-cursor\update-cursor-settings\"
```
