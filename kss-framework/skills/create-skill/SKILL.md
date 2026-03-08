---
name: create-skill
description: Guides users through creating effective Agent Skills for Cursor. Use when you want to create, write, or author a new skill, or asks about skill structure, best practices, or SKILL.md format.
---
# Creating Skills in Cursor

This skill guides you through creating effective Agent Skills for Cursor.

## Before You Begin: Gather Requirements

Before creating a skill, gather essential information from the user about:

1. **Purpose and scope**: What specific task or workflow should this skill help with?
2. **Target location**: Should this be a personal skill (~/.cursor/skills/) or project skill (.cursor/skills/)?
3. **Trigger scenarios**: When should the agent automatically apply this skill?
4. **Key domain knowledge**: What specialized information does the agent need?
5. **Output format preferences**: Are there specific templates, formats, or styles required?

## Skill File Structure

### Directory Layout

Skills are stored as directories containing a `SKILL.md` file:

```
skill-name/
├── SKILL.md              # Required - main instructions
├── reference.md          # Optional - detailed documentation
├── examples.md           # Optional - usage examples
└── scripts/              # Optional - utility scripts
```

### Storage Locations

| Type | Path | Scope |
|------|------|-------|
| Personal | ~/.cursor/skills/skill-name/ | Available across all your projects |
| Project | .cursor/skills/skill-name/ | Shared with anyone using the repository |

### SKILL.md Structure

Every skill requires a `SKILL.md` file with YAML frontmatter and markdown body:

```markdown
---
name: your-skill-name
description: Brief description of what this skill does and when to use it
---

# Your Skill Name

## Instructions
Clear, step-by-step guidance for the agent.

## Examples
Concrete examples of using this skill.
```

### Required Metadata Fields

| Field | Requirements | Purpose |
|-------|--------------|---------|
| `name` | Max 64 chars, lowercase letters/numbers/hyphens only | Unique identifier |
| `description` | Max 1024 chars, non-empty | Helps agent decide when to apply |

## Core Authoring Principles

### 1. Concise is Key

The context window is shared with conversation history. Every token competes for space.

### 2. Keep SKILL.md Under 500 Lines

For optimal performance, the main SKILL.md file should be concise.

### 3. Progressive Disclosure

Put essential information in SKILL.md; detailed reference material in separate files.

## Summary Checklist

Before finalizing a skill, verify:

- [ ] Description is specific and includes key terms
- [ ] Written in third person
- [ ] SKILL.md body is under 500 lines
- [ ] Consistent terminology throughout
- [ ] Examples are concrete, not abstract
