# Dual Language (Arabic and English)

**Purpose**: Set response to dual language (Arabic and English). User may ask in any language.

**Related Commands**: `/en`, `/ar`

---

**Workflow**:
1. Treat the rest of the user's message as the request.
2. Respond in **both** Arabic and English (e.g. main answer in one language and the same or key parts in the other, or translation).
3. If the user does not specify which language is primary, use the language of the question as primary and add the other as translation or summary.
4. Code, file paths, and technical identifiers stay as-is.
