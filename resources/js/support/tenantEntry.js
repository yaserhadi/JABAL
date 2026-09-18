/**
 * Canonical tenant path/host key from Inertia props (BK-066 / BK-073 / BK-107 / BK-125).
 * Prefer backend-provided entryKey; slug/id are compatibility fallbacks only.
 */
export function tenantEntry(tenant) {
    if (!tenant) {
        return undefined;
    }
    return tenant.entryKey || tenant.slug || tenant.id;
}

/**
 * True when the active addressing profile uses PATH_HOST semantics.
 */
export function isPathHostProfile(profile) {
    return profile === 'path_host';
}

/**
 * Build Ziggy route params for tenant-scoped named routes (BK-107 / BK-125).
 *
 * PATH_HOST (path_host, or temporary alias host): { tenant_label } — Laravel domain param.
 * PATH (path): { tenant } — path param.
 * Unknown / unsupported: throw — never silent Path↔PATH_HOST fallback.
 *
 * Mirrors App\Http\Auth\TenantEntryUrlResolver::namedRouteUrl.
 */
export function tenantRouteParams(tenant, extra = {}) {
    const profile = typeof window !== 'undefined'
        ? window.__jabalAddressingProfile
        : undefined;

    if (isPathHostProfile(profile)) {
        const key = tenantEntry(tenant);
        return key ? { tenant_label: key, ...extra } : { ...extra };
    }

    if (profile === 'path') {
        const key = tenantEntry(tenant);
        return key ? { tenant: key, ...extra } : { ...extra };
    }

    throw new Error(
        `Unsupported or missing tenancy addressing profile for Ziggy route params: ${String(profile)}`,
    );
}
