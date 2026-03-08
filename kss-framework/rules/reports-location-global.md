# Global Rule: Report Storage Location

## Scope: ALL PROJECTS

This rule applies to **all Cursor projects** globally, unless overridden by project-specific rules.

## Rule: Store Reports in `.cursor/reports/`

**When creating status, progress, closure, or audit reports:**

1. **ALWAYS** create report files in `.cursor/reports/` directory
2. **NEVER** create reports in project root
3. If `.cursor/reports/` doesn't exist, create it first
4. Use consistent naming: `{PHASE}_{TYPE}_REPORT.md`

## Report Types (Examples)

Reports include, but are not limited to:
- Phase closure reports (`PHASE1_CLOSURE_REPORT.md`)
- Implementation status reports (`IMPLEMENTATION_STATUS.md`)
- Gate review reports (`GATE_REVIEW_REPORT.md`)
- Performance audit reports (`PERFORMANCE_AUDIT.md`)
- Architecture compliance reports (`ARCHITECTURE_COMPLIANCE_REPORT.md`)
- Progress summaries (`PROGRESS_SUMMARY.md`)

**Detection Keywords:**
- Filename contains: `REPORT`, `STATUS`, `AUDIT`, `CLOSURE`, `GATE`
- Content purpose: Temporal project state documentation

## File Organization

```
project-root/
├── .cursor/
│   ├── plans/          # Future work plans
│   ├── reports/        # ✅ Status reports (this rule)
│   └── rules/          # Project-specific rules
├── docs/               # Permanent technical documentation
├── memory/             # Knowledge base
└── README.md           # Project overview
```

## Why This Rule Exists

1. **Consistency**: All projects follow same report storage pattern
2. **Clean Root**: Project root stays free of temporal documents
3. **Version Control**: Easy to include/exclude reports from git
4. **Discoverability**: Always know where to find project reports
5. **Tooling**: AI agents and scripts know where to create/find reports

## AI Agent Workflow

```bash
# Step 1: Check for reports directory
if not exists .cursor/reports/
    create directory .cursor/reports/

# Step 2: Create report in correct location
write .cursor/reports/{REPORT_NAME}.md

# Step 3: Create index if needed
if not exists .cursor/reports/README.md
    create .cursor/reports/README.md with report index
```

## Exclusions

**Not considered "reports"** (different storage rules):
- Technical documentation → `docs/`
- Architecture decisions → project ADR path (per DOC_POLICY, e.g., `docs/architecture/ADR/`)
- API documentation → `docs/api/`
- README files → Project root
- CHANGELOG → Project root
- Contributing guidelines → `CONTRIBUTING.md`

**Note:** Do not hardcode ADR paths in global rules; use the project ADR path defined in DOC_POLICY.

## Project-Specific Overrides

Projects can override this rule by creating:
```
.cursor/rules/reports-location.md
```

With project-specific requirements.

## Enforcement Priority

1. **Project-specific rule** (`.cursor/rules/reports-location.md`) - Highest
2. **This global rule** - Default for all projects
3. **No rule** - Agent decides (inconsistent)

---

**Rule Type**: Global (All Projects)
**Priority**: Default (can be overridden)
**Status**: Active
