/**
 * Canonical tenant path/host key from Inertia props (BK-066 / BK-073 / BK-107).
 * Prefer backend-provided entryKey; slug/id are compatibility fallbacks only.
 */
export function tenantEntry(tenant) {
    if (!tenant) {
        return undefined;
    }
    return tenant.entryKey || tenant.slug || tenant.id;
}

/**
 * Build Ziggy route params for tenant-scoped named routes (BK-107).
 *
 * Host (TENANCY_ADDRESSING_PROFILE=host): { tenant_label } — Laravel domain param.
 * Path (profile=path only when that profile is active): { tenant } — path param.
 * Unknown / unsupported: throw — never silent Path fallback.
 *
 * Mirrors App\Http\Auth\TenantEntryUrlResolver::namedRouteUrl.
 */
export function tenantRouteParams(tenant, extra = {}) {
    const profile = typeof window !== 'undefined'
        ? window.__jabalAddressingProfile
        : undefined;

    if (profile === 'host') {
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
