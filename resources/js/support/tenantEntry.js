/**
 * Canonical tenant path key from Inertia props (BK-066 / BK-073).
 * Prefer backend-provided entryKey; slug/id are compatibility fallbacks only.
 *
 * In Host profile, Ziggy tenant routes have no {tenant} parameter — callers
 * should omit the tenant key (routeParams below).
 */
export function tenantEntry(tenant) {
    if (!tenant) {
        return undefined;
    }
    return tenant.entryKey || tenant.slug || tenant.id;
}

/**
 * Build Ziggy route params for tenant-scoped named routes.
 * Host profile: empty object (implicit Tenant from host). Path profile: { tenant }.
 */
export function tenantRouteParams(tenant, extra = {}) {
    const profile = typeof window !== 'undefined'
        ? window.__jabalAddressingProfile
        : undefined;

    if (profile === 'host') {
        return { ...extra };
    }

    const key = tenantEntry(tenant);
    return key ? { tenant: key, ...extra } : { ...extra };
}
