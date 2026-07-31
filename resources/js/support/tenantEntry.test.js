import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { route } from 'ziggy-js';
import { tenantEntry, tenantRouteParams } from './tenantEntry.js';

/** Minimal Host-shaped Ziggy fixture (mirrors Laravel {tenant_label} domain param). */
const hostZiggy = {
    url: 'https://platform.jabal.test',
    port: null,
    defaults: {},
    routes: {
        'tenant.login.submit': {
            uri: 'login',
            methods: ['POST'],
            domain: '{tenant_label}.jabal.test',
            parameters: ['tenant_label'],
        },
        dashboard: {
            uri: 'dashboard',
            methods: ['GET', 'HEAD'],
            domain: '{tenant_label}.jabal.test',
            parameters: ['tenant_label'],
        },
        'workspaces.show': {
            uri: 'workspaces/{workspace}',
            methods: ['GET', 'HEAD'],
            domain: '{tenant_label}.jabal.test',
            parameters: ['tenant_label', 'workspace'],
        },
    },
};

describe('tenantEntry', () => {
    it('prefers entryKey then slug then id', () => {
        expect(tenantEntry({ entryKey: 'acme-uat', slug: 's', id: 'i' })).toBe('acme-uat');
        expect(tenantEntry({ slug: 'acme', id: 'i' })).toBe('acme');
        expect(tenantEntry({ id: 'uuid' })).toBe('uuid');
        expect(tenantEntry(null)).toBeUndefined();
    });
});

describe('tenantRouteParams', () => {
    beforeEach(() => {
        globalThis.window = globalThis.window || {};
    });

    afterEach(() => {
        delete globalThis.window.__jabalAddressingProfile;
    });

    it('Host profile emits tenant_label and preserves extras', () => {
        window.__jabalAddressingProfile = 'host';
        expect(tenantRouteParams({ entryKey: 'acme-uat' })).toEqual({ tenant_label: 'acme-uat' });
        expect(tenantRouteParams({ entryKey: 'acme-uat' }, { workspace: 9 })).toEqual({
            tenant_label: 'acme-uat',
            workspace: 9,
        });
        expect(tenantRouteParams(null)).toEqual({});
    });

    it('Path profile emits tenant only when profile is explicitly path (not revived for Host UAT)', () => {
        window.__jabalAddressingProfile = 'path';
        expect(tenantRouteParams({ entryKey: 'acme' }, { user: 1 })).toEqual({
            tenant: 'acme',
            user: 1,
        });
    });

    it('unsupported or missing profile fails explicitly', () => {
        delete window.__jabalAddressingProfile;
        expect(() => tenantRouteParams({ entryKey: 'acme' })).toThrow(/Unsupported or missing/);
        window.__jabalAddressingProfile = 'host_redirect';
        expect(() => tenantRouteParams({ entryKey: 'acme' })).toThrow(/Unsupported or missing/);
    });
});

describe('Host Ziggy URL generation via tenantRouteParams', () => {
    beforeEach(() => {
        globalThis.window = globalThis.window || {};
        window.__jabalAddressingProfile = 'host';
        globalThis.Ziggy = hostZiggy;
    });

    afterEach(() => {
        delete globalThis.window.__jabalAddressingProfile;
        delete globalThis.Ziggy;
    });

    it('places tenant label in hostname with no unresolved placeholder or tenant query', () => {
        const url = route(
            'tenant.login.submit',
            tenantRouteParams({ entryKey: 'acme-uat' }),
            true,
            hostZiggy,
        );

        expect(url).toBe('https://acme-uat.jabal.test/login');
        expect(url).not.toContain('{tenant_label}');
        expect(url).not.toMatch(/[?&]tenant=/);
    });

    it('preserves extra route parameters on Host URLs', () => {
        const url = route(
            'workspaces.show',
            tenantRouteParams({ entryKey: 'acme-uat' }, { workspace: 42 }),
            true,
            hostZiggy,
        );

        expect(url).toBe('https://acme-uat.jabal.test/workspaces/42');
        expect(url).not.toMatch(/[?&]tenant=/);
    });
});
