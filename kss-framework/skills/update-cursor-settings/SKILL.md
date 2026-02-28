---
name: update-cursor-settings
description: Modify Cursor/VSCode user settings in settings.json. Use when you want to change editor settings, preferences, configuration, themes, font size, tab size, format on save, auto save, keybindings, or any settings.json values.
---
# Updating Cursor Settings

This skill guides you through modifying Cursor/VSCode user settings.

## Settings File Location

| OS | Path |
|----|------|
| macOS | ~/Library/Application Support/Cursor/User/settings.json |
| Linux | ~/.config/Cursor/User/settings.json |
| Windows | %APPDATA%\Cursor\User\settings.json |

## Before Modifying Settings

1. **Read the existing settings file** to understand current configuration
2. **Preserve existing settings** - only add/modify what the user requested
3. **Validate JSON syntax** before writing to avoid breaking the editor

## Modifying Settings

### Step 1: Read Current Settings

Read the settings file first to understand the current state.

### Step 2: Identify the Setting to Change

Common setting categories:
- **Editor**: `editor.fontSize`, `editor.tabSize`, `editor.wordWrap`, `editor.formatOnSave`
- **Workbench**: `workbench.colorTheme`, `workbench.iconTheme`
- **Files**: `files.autoSave`, `files.exclude`
- **Terminal**: `terminal.integrated.fontSize`

### Step 3: Update the Setting

1. Parse the existing JSON
2. Add or update the requested setting
3. Preserve all other existing settings
4. Write back with proper formatting (2-space indentation)

## Common User Requests → Settings

| User Request | Setting |
|--------------|---------|
| "bigger/smaller font" | `editor.fontSize` |
| "change tab size" | `editor.tabSize` |
| "format on save" | `editor.formatOnSave` |
| "word wrap" | `editor.wordWrap` |
| "change theme" | `workbench.colorTheme` |
| "hide minimap" | `editor.minimap.enabled` |
| "auto save" | `files.autoSave` |

## Workflow

1. Read settings file
2. Parse the JSON content
3. Add/modify the requested setting(s)
4. Write the updated JSON back to the file
5. Inform the user whether a reload is needed
