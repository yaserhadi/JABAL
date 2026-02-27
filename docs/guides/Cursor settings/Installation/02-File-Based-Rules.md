# 02 — File-Based Rules

**To:** All Development Team Members  
**Purpose:** Install 3 file-based rules in `~/.cursor/rules/`

## Files to Copy

Copy from `Installation/rules/` to `~/.cursor/rules/` (Windows: `C:\Users\<You>\.cursor\rules\`):

| File | Purpose |
|------|---------|
| plans-location.mdc | Plans in project `.cursor/plans/` only |
| reports-location-global.md | Reports in `.cursor/reports/` |
| agent-response-language-global.md | Default response: English |

## Windows

```powershell
if (-not (Test-Path "$env:USERPROFILE\.cursor\rules")) { mkdir "$env:USERPROFILE\.cursor\rules" }
Copy-Item ".\rules\*" "$env:USERPROFILE\.cursor\rules\"
```

## Mac/Linux

```bash
mkdir -p ~/.cursor/rules
cp rules/* ~/.cursor/rules/
```
