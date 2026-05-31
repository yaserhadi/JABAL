# Tenancy environment variables

This guide explains the **tenancy and database settings** in your `.env` file when you set up or operate Jabal locally or on a server.

**Who this is for:** Anyone copying `.env.example` to `.env` and asking: *What does this line do? What values can I use? What should I leave alone?*

**How to use this page:**

1. Skim the [Quick reference](#quick-reference-cheat-sheet) if you only need the recommended values.
2. Read the **full sections below** for each variable — purpose, allowed values, examples, and when it is safe to change.
3. Run the [smoke checks](#smoke-checks-after-setup) after first setup.

For architecture background (optional): [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md).

---

## Quick reference (cheat sheet)

Use these defaults unless your team has told you otherwise. **This table is a summary only** — each row links to a full explanation below.

| Variable | Set this to | Other allowed values |
|----------|-------------|----------------------|
| `DB_CONNECTION` | `central` | `tenant` (not recommended as default) → [details](#db_connection) |
| `DB_DATABASE_CENTRAL` | `jabal_central` | Any PostgreSQL database name you create → [details](#db_database_central--db_database_tenant) |
| `DB_DATABASE_TENANT` | `jabal_tenant_shared` | Any PostgreSQL database name you create → [details](#db_database_central--db_database_tenant) |
| `TENANCY_MODE` | `shared_db` | `database_per_tenant`, `schema_per_tenant` (future) → [details](#tenancy_mode) |
| `TENANCY_IDENTIFICATION` | `path` | `request_data`, `domain`, `subdomain` → [details](#tenancy_identification) |
| `TENANCY_SHARED_DB_CONNECTION` | `tenant` | Must match a connection in `config/database.php` → [details](#tenancy_shared_db_connection) |
| `TENANCY_DB_CREATION_MODE` | `manual` | `automatic` (future) → [details](#tenancy_db_creation_mode) |
| `TENANCY_DEFAULT_ISOLATION` | `shared` | `schema`, `database` → [details](#tenancy_default_isolation) |
| `TENANCY_ALLOW_DATABASE_PER_TENANT` | `true` | `true` / `false` → [details](#tenancy_allow_database_per_tenant) |
| `TENANCY_ALLOW_SCHEMA_PER_TENANT` | `false` | `true` / `false` → [details](#tenancy_allow_schema_per_tenant) |
| `SESSION_DRIVER` | `database` | Other Laravel drivers not supported for Jabal today → [details](#session_driver) |
| `SESSION_CONNECTION` | `tenant` | `central`, empty → [details](#session_connection) |
| `PLATFORM_SESSION_COOKIE` | `jabal_platform_session` | Any unique cookie name → [details](#platform_session_cookie--tenant_session_cookie) |
| `TENANT_SESSION_COOKIE` | `jabal_tenant_session` | Any unique cookie name → [details](#platform_session_cookie--tenant_session_cookie) |

---

## Two databases (read this first)

Jabal uses **two PostgreSQL databases**:

| Database | Typical name | What lives here |
|----------|--------------|-----------------|
| **Central** | `jabal_central` | Platform admins, tenant registry, membership links, platform login sessions |
| **Tenant (shared)** | `jabal_tenant_shared` | End-user accounts, tenant login sessions, roles, workspaces |

They must **never** be the same database. After a fresh install you migrate **both**:

```bash
php artisan migrate:fresh
php artisan migrate --path=database/migrations/tenant --database=tenant
```

More setup steps: [UAT environment setup §2.2](../reports/UAT_STAGE_1_2_2_5.md).

---

## Database variables

These appear **above** the tenancy block in `.env.example`.

### `DB_CONNECTION`

**In plain terms:** Tells Laravel which database connection to use by default when a command does not specify one (for example `php artisan migrate:fresh` runs against the **central** database).

**Recommended value:** `central`

#### Allowed values

| Value | When to use |
|-------|-------------|
| `central` | Normal Jabal setup. Use this. |
| `tenant` | Only if you intentionally want the default connection to point at the tenant database. Not recommended — tenant migrations already use `--database=tenant`. |

Laravel also defines `sqlite`, `mysql`, etc. in `config/database.php`. Do not switch Jabal to those without a formal architecture decision.

**How to check:**

```bash
php artisan tinker --execute="echo config('database.default');"
```

---

### `DB_DATABASE_CENTRAL` / `DB_DATABASE_TENANT`

**In plain terms:** The **actual PostgreSQL database names** on your server — not the words `central` or `tenant` (those are connection *keys* in Laravel config).

**Recommended values:**

```env
DB_DATABASE_CENTRAL=jabal_central
DB_DATABASE_TENANT=jabal_tenant_shared
```

#### Allowed values

Any valid PostgreSQL database name you have created. There is no fixed list — you choose names when you `CREATE DATABASE`.

**Rules:**

- The two names must be **different**.
- Create both databases in PostgreSQL before running migrations.
- Automated tests use separate names (`jabal_central_testing`, `jabal_tenant_shared_testing`) via `phpunit.xml` so tests do not touch your dev data.

**What each store holds:**

- **Central:** `platform_users`, `tenants`, `tenant_users`, `platform_sessions`, …
- **Tenant:** `users`, `sessions`, roles/permissions, workspaces, …

---

## Tenancy block

Copy this block from `.env.example` (line numbers may vary):

```env
TENANCY_MODE=shared_db
TENANCY_IDENTIFICATION=path
TENANCY_SHARED_DB_CONNECTION=tenant
TENANCY_DB_CREATION_MODE=manual
TENANCY_DEFAULT_ISOLATION=shared
TENANCY_ALLOW_DATABASE_PER_TENANT=true
TENANCY_ALLOW_SCHEMA_PER_TENANT=false
```

These map to [`config/tenancy_storage.php`](../../config/tenancy_storage.php).

---

### `TENANCY_MODE`

**In plain terms:** How tenant **data** is stored across your deployment — one shared database for all tenants today, or (in a future release) separate databases or schemas per tenant.

**Recommended value:** `shared_db`

#### Allowed values (3)

| Value | What it means | Can I use it now? |
|-------|---------------|-------------------|
| `shared_db` | All tenants share `jabal_tenant_shared`. Each row that belongs to a tenant has a `tenant_id` column. | **Yes** — this is the only mode validated in UAT. |
| `database_per_tenant` | Allows giving some tenants their own PostgreSQL database. | **No** — planned for a later release; changing this today does not enable it. |
| `schema_per_tenant` | Allows giving some tenants their own PostgreSQL schema. | **No** — planned for a later release. |

Do not invent other mode names. Only the three values above are defined.

**When would I change this?** Only as part of a planned migration project with your architecture team — not for normal local development.

**How to check:**

```bash
php artisan tinker --execute="echo config('tenancy_storage.mode');"
# Should print: shared_db
```

---

### `TENANCY_IDENTIFICATION`

**In plain terms:** Documents **how the app knows which tenant you are using** on a request (URL path, API header, domain, etc.).

**Recommended value:** `path`

#### Allowed values (4)

| Value | What it means | Works in Jabal today? |
|-------|---------------|------------------------|
| `path` | Tenant UUID in the web URL, e.g. `/t/a1e24428-…/dashboard`. | **Yes** — this is how the web app works. |
| `request_data` | Tenant sent in the request (API uses the `X-Tenant-Id` header). | **Yes for API** — but the API does not read this env var; it uses fixed middleware. |
| `domain` | Each tenant has its own hostname. | **No** — not implemented yet. |
| `subdomain` | Tenant as a subdomain, e.g. `acme.app.example.com`. | **No** — not implemented yet. |

**Important:** Changing this variable in `.env` **does not change app behavior today**. It records the intended strategy for future work. Web uses path-based URLs; API uses `X-Tenant-Id`.

**When would I change this?** Leave as `path` until product documentation announces domain/subdomain support.

---

### `TENANCY_SHARED_DB_CONNECTION`

**In plain terms:** Which Laravel **connection name** points at the shared tenant database when all tenants use one DB (`shared_db` mode).

**Recommended value:** `tenant`

#### Allowed values

| Value | Meaning |
|-------|---------|
| `tenant` | Uses the `tenant` entry in `config/database.php` → database `DB_DATABASE_TENANT`. **Use this.** |

**Do not set to:**

| Value | Why |
|-------|-----|
| `central` | End-user data must not live on the central database. |
| A random name | Must match a defined connection or the app will error. |

**When would I change this?** Almost never on a standard install. Only if your team renames connections in `config/database.php` and updates documentation to match.

---

### `TENANCY_DB_CREATION_MODE`

**In plain terms:** Whether the system **automatically creates** a new database (or schema) when a new tenant is registered, or whether **you** create and migrate databases manually.

**Recommended value:** `manual`

#### Allowed values (2)

| Value | Meaning |
|-------|---------|
| `manual` | You run migrations and provisioning yourself. **Current behavior.** |
| `automatic` | System creates tenant DB/schema on registration. **Not implemented yet.** |

Setting `automatic` today has **no effect**.

**When would I change this?** When automatic tenant provisioning is released and documented.

---

### `TENANCY_DEFAULT_ISOLATION`

**In plain terms:** Default **isolation level** for a tenant when the system cannot determine one from the tenant record. Uses the same words as the `isolation_level` column on the `tenants` table.

**Recommended value:** `shared`

#### Allowed values (3)

| Value | Meaning | Can I use it now? |
|-------|---------|-------------------|
| `shared` | Data in the shared tenant database with `tenant_id` on rows. | **Yes** |
| `schema` | Separate PostgreSQL schema per tenant. | **No** — future |
| `database` | Separate PostgreSQL database per tenant. | **No** — future |

With `TENANCY_MODE=shared_db`, every tenant effectively uses `shared` isolation anyway. This variable mainly matters when non-shared modes are enabled later.

The same three values are validated in the platform settings UI and stored in the database.

**When would I change this?** Leave as `shared` for current installs.

---

### `TENANCY_ALLOW_DATABASE_PER_TENANT`

**In plain terms:** A **yes/no switch** (for a future release): “Are we allowed to put some tenants on their own database?”

**Recommended value:** `true`

#### Allowed values (2)

| Value | Meaning |
|-------|---------|
| `true` | Future per-tenant databases are permitted when mode and tenant settings allow it. |
| `false` | Force everyone to stay on the shared tenant database even in a per-DB deployment mode. |

You can write `true`, `false`, `1`, or `0` in `.env` — Laravel treats them as booleans.

**Effect today:** None while `TENANCY_MODE=shared_db`. Safe to leave as `true`.

---

### `TENANCY_ALLOW_SCHEMA_PER_TENANT`

**In plain terms:** A **yes/no switch** (for a future release): “Are we allowed to put some tenants on their own PostgreSQL schema?”

**Recommended value:** `false`

#### Allowed values (2)

| Value | Meaning |
|-------|---------|
| `false` | Schema-per-tenant is off. **Recommended until that feature ships.** |
| `true` | Schema-per-tenant allowed when mode and tenant settings allow it. |

**Effect today:** None. Leave as `false`.

---

## Session variables (login cookies)

These settings are **separate from tenancy data** but belong in the same setup checklist. They control **where login sessions are stored** for the platform admin app vs the tenant app.

- Platform admins (`/platform/login`) → sessions in **`jabal_central.platform_sessions`**
- Tenant users (`/login`, `/t/…`) → sessions in **`jabal_tenant_shared.sessions`**
- Two different **cookie names** so logging out of one does not log you out of the other

See also `.env.example` and ADR-0007 §3.1.3.1.

---

### `SESSION_DRIVER`

**In plain terms:** Where Laravel stores session data.

**Recommended value:** `database`

#### Allowed values

| Value | Use with Jabal? |
|-------|-----------------|
| `database` | **Yes** — required for the dual-session design tested in UAT. |
| `file`, `redis`, `array`, … | **No** — not supported for platform/tenant session separation unless the project is re-engineered. |

---

### `SESSION_CONNECTION`

**In plain terms:** Which database connection artisan and tests use for sessions when **no web request** is running. It does **not** override platform web sessions — the app sets the correct store per route at runtime.

**Recommended value:** `tenant`

#### Allowed values

| Value | Meaning |
|-------|---------|
| `tenant` | CLI/tests use the tenant DB `sessions` table. **Recommended.** |
| `central` | Would use central DB for generic sessions. Not recommended as default. |
| Empty / unset | May fall back to `DB_CONNECTION` (`central`). Avoid — can confuse debugging. |

---

### `PLATFORM_SESSION_COOKIE` / `TENANT_SESSION_COOKIE`

**In plain terms:** The **names of the browser cookies** for platform vs tenant login. They must be different so both sessions can exist in one browser.

**Recommended values:**

```env
PLATFORM_SESSION_COOKIE=jabal_platform_session
TENANT_SESSION_COOKIE=jabal_tenant_session
```

#### Allowed values

Any non-empty cookie name string. No fixed list.

**Rules:** The two names must **not** be identical. Change them only if you run multiple Jabal environments on the same domain and need to avoid cookie clashes.

---

## Smoke checks after setup

After copying `.env`, creating both databases, and migrating:

1. **Mode:** `php artisan tinker --execute="echo config('tenancy_storage.mode');"` → `shared_db`
2. **Tenant users:** Rows in `jabal_tenant_shared.users` have a non-null `tenant_id`
3. **Platform admins:** Rows in `jabal_central.platform_users`; platform admin email is **not** in tenant `users`

Full UAT checklist: [Suite F](../reports/UAT_STAGE_1_2_2_5.md).

---

## When to change values (summary)

| You want to… | Safe? |
|--------------|-------|
| Set up local dev with defaults from `.env.example` | **Yes** |
| Rename PostgreSQL databases (`DB_DATABASE_*`) | **Yes** — if you create the DBs and re-migrate |
| Change `TENANCY_MODE` away from `shared_db` | **No** — not supported in current release |
| Point tenant data at `central` | **Never** |
| Change cookie names for multi-env on same domain | **Yes** — keep platform and tenant names different |

For production or security-sensitive changes, follow your team’s change approval process.

---

## See also

- [`.env.example`](../../.env.example) — copy-paste template
- [ADR-0007](../architecture/ADR/ADR-0007-platform-tenant-application-separation.md) — architecture (optional deep read)
- [JABAL Core Realignment](../reports/JABAL_CORE_REALIGNMENT.md) — project stages
