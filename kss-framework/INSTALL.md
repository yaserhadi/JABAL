# KSS Installation Guide

This guide walks you through setting up KSS in your development environment.

---

## Installation Order

Follow this exact order:

### 1. Global User Rules

Copy the content from `rules/` files into your Cursor Settings UI:
- Settings > Rules > User Rules

### 2. File-Based Rules

Copy rule files to your global Cursor rules folder:

```
~/.cursor/rules/
├── plans-location.mdc
├── module-creation-gate.mdc
├── reports-location-global.md
└── agent-response-language-global.md
```

Also copy `kss-framework/rules/module-creation-gate.mdc` into the **project** `.cursor/rules/` when adopting workspace rules (MODULE-CREATION-GATE; pairs with `/gw-triage` and ADR-0003).

### 3. Commands

Copy command files to your global Cursor commands folder:

```
~/.cursor/commands/
├── boot.md
├── session-end.md
├── docpack.md
├── adr.md
├── en.md
├── ar.md
├── aren.md
├── gw-triage.md
├── gw-riskcheck.md
├── gw-handoff.md
├── git-prepare.md
├── git-save.md
└── git-finalize.md
```

### 4. Agents

Copy agent files to your global Cursor agents folder:

```
~/.cursor/agents/
├── adr-steward.md
├── ai-knowledge-steward.md
└── user-doc-steward.md
```

### 5. Skills

Copy skill folders to your global Cursor skills folder:

```
~/.cursor/skills/
├── safe-coding-practices/SKILL.md
├── create-rule/SKILL.md
├── create-skill/SKILL.md
└── update-cursor-settings/SKILL.md
```

### 6. Project Setup

For each project adopting KSS:

1. Create `.cursor/` folder structure:
   ```
   .cursor/
   ├── DOC_POLICY.yaml
   ├── memory/
   │   ├── AI_ENTRY.md
   │   ├── PROJECT_MANIFEST.md
   │   ├── INTEGRITY_RULES.md
   │   ├── STATE.yaml
   │   ├── VERSIONS.md
   │   └── HANDOFF.md
   ├── plans/
   │   └── ACTIVE.plan.md
   └── reports/
   ```

2. Copy templates from `templates/` to `.cursor/memory/`
3. Fill templates with your project-specific content

---

## What Gets Installed Where

| Source | Destination | Filled by |
|--------|-------------|-----------|
| commands/, rules/, agents/, skills/ | `~/.cursor/...` (global) | Framework (copy as-is) |
| templates/ | `.cursor/memory/` (project) | Adopter (fill with project content) |

Framework provides structure. Project provides content.
Templates are scaffolds, NOT policies.

---

## Optional Components

The `conventions/` folder is optional. If not needed, the adopter may leave it absent.
However, DOC_POLICY.template.yaml keeps the reserved path for future use.

---

## Verification

After installation, verify by running `/boot` in Cursor. The agent should:
1. Find and read `.cursor/DOC_POLICY.yaml`
2. Load memory files in correct order
3. Report current STATE

If `/boot` fails, check that all paths in DOC_POLICY.yaml point to existing files.

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| `/boot` command not found | Copy commands to `~/.cursor/commands/` |
| Memory files not loading | Check DOC_POLICY.yaml paths |
| Agent not following rules | Verify rules are in `~/.cursor/rules/` |
| Skills not available | Copy to `~/.cursor/skills/` |
