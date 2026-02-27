# Global Rule: Agent Response Language

## Scope: ALL PROJECTS

This is a **CRITICAL** rule that applies to all Cursor projects unless explicitly overridden.

## 🚨 MANDATORY RULE 🚨

**Agent MUST ALWAYS respond in English ONLY**, regardless of user's language.

## The Rule

- **User**: May write in ANY language (Arabic, English, mixed, etc.)
- **Agent**: MUST ALWAYS respond in English only

**No exceptions.**

## Why This Rule Exists

1. **Documentation Consistency**: All technical artifacts in English
2. **Code Context**: Technical terms and code stay consistent
3. **International Collaboration**: English is working language
4. **Searchability**: English responses are easier to search/index
5. **AI Model Performance**: Best accuracy in English

## AI Agent Enforcement

**BEFORE EVERY RESPONSE:**
```
1. Understand user message (any language)
2. Formulate helpful response
3. CHECK: Is my response 100% English?
4. If NO: Rewrite in English
5. Send response
```

**Violation Check:**
- ❌ Response contains Arabic text → VIOLATION
- ❌ Response contains other non-English text → VIOLATION
- ❌ Response mixes English and other languages → VIOLATION
- ✅ Response is 100% English → CORRECT

## Correct Behavior Examples

### User writes in Arabic:
**User:** "كيف أنشئ قاعدة جديدة؟"  
**Agent:** "To create a new rule, create a file in `.cursor/rules/` directory..."

### User explicitly requests Arabic:
**User:** "اكتب الرد بالعربي"  
**Agent:** "I understand you'd like the response in Arabic. However, project rules require all agent responses to be in English only."

### User writes in mixed language:
**User:** "Move this file و تأكد من الصلاحيات"  
**Agent:** "I'll move the file and verify the permissions..."

## Override for Specific Projects

If a project needs different language rules, create:
```
.cursor/rules/agent-response-language.mdc
```

Project-specific rules take precedence over this global rule.

## Exclusions

**This rule does NOT apply to:**
- File contents (can be any language)
- User messages (can be any language)
- Comments in code (follow project standards)
- Documentation written to files (follow project standards)

**This rule ONLY applies to:**
- Natural language agent responses in chat
- Explanations and instructions from agent
- Error messages and warnings from agent

---

**Rule Type**: Global (All Projects)  
**Priority**: CRITICAL  
**Enforcement**: STRICT  
**Created**: 2026-02-05  
**Status**: Active
