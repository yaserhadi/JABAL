/**
 * Canonical tenant path key from Inertia props (BK-066).
 * Prefer backend-provided entryKey; slug/id are compatibility fallbacks only.
 */
export function tenantEntry(tenant) {
    if (!tenant) {
        return undefined;
    }
    return tenant.entryKey || tenant.slug || tenant.id;
}
