# Tenancy environment — addressing profiles (BK-073 / DEC-0023)

## Profiles

| Value | Meaning |
|-------|---------|
| `host` | DEC-0023 Profile A — `{handle}.{TENANT_PLATFORM_BASE_DOMAIN}` Tenant Hosts (architectural default) |
| `path` | DEC-0023 Profile B — `/t/{handle\|uuid}/…` on the platform origin |
| `host_redirect` | Profile C — **not implemented** in BK-073 (fails boot; deferred to BK-096) |

Set via `TENANCY_ADDRESSING_PROFILE` (read only inside `config/tenancy_addressing.php`).

## Canonical origin

Absolute URLs (`entry_url`, login, dashboard, emails) are built from:

- `TENANCY_CANONICAL_SCHEME`
- Host profile: `{handle}.{TENANT_PLATFORM_BASE_DOMAIN}`
- Path profile: `TENANCY_PLATFORM_HOST`
- Optional `TENANCY_CANONICAL_PORT` for local non-standard ports

**Never** derived from the untrusted request `Host` header.

## Local Host development (verified path)

1. Add hosts entries (example):

   ```
   127.0.0.1 jabal.test platform.jabal.test auth.jabal.test api.jabal.test
   127.0.0.1 acme.jabal.test
   ```

2. Set in `.env`:

   ```
   TENANCY_ADDRESSING_PROFILE=host
   TENANT_PLATFORM_BASE_DOMAIN=jabal.test
   TENANCY_PLATFORM_HOST=platform.jabal.test
   TENANCY_AUTH_HOST=auth.jabal.test
   TENANCY_CANONICAL_SCHEME=http
   TENANCY_CANONICAL_PORT=8000
   APP_URL=http://platform.jabal.test:8000
   SESSION_DOMAIN=null
   ```

3. Run `php artisan tenants:backfill-platform-domains` once for existing Tenants.

4. Serve with `php artisan serve --host=0.0.0.0 --port=8000` (or your stack).

### `.localhost` candidate

`{handle}.localhost` remains a **candidate only** until DNS, cookies, Vite HMR, and target browsers are verified. Do not treat it as acceptance criteria yet.

## Cookies

- `SESSION_DOMAIN` must remain `null` (host-only cookies).
- Platform cookie: `jabal_platform_session` (central `/platform/*`).
- Tenant cookie: `jabal_tenant_session` (Tenant Hosts / path tenant surfaces).
- Parent-domain Tenant session cookies are **not** the default.

## Trusted proxies

- Never set `TENANCY_TRUSTED_PROXIES=*`.
- Enable `TENANCY_TRUST_FORWARDED_HEADERS=true` only with an explicit IP/CIDR list.
- Boot fails if forwarded mode is enabled without a trusted list.

## Enterprise SSO (Host mode)

Host-mode Enterprise SSO is **negatively gated** until BK-082 (UI hidden + initiation endpoint `404`). Path-mode SSO remains regression-green.
