---
name: Phase 1 Technical Foundation
overview: "Corrected Phase 1 TODO list for SaaS Core Platform (Jabal) - FINAL VERSION with all reviewer feedback applied: removes tenants.settings JSON, reorders API before Frontend, removes Core module, adds tenant resolution strategy, simplifies events list, central-only settings."
todos:
  - id: repo-tooling
    content: "1. Repository & Tooling: Laravel 12, PostgreSQL, Pint, PHPUnit, CI, DB conventions (documented, no BaseMigration), Testing foundation"
    status: completed
  - id: modules-arch
    content: "2. Modular Architecture: nWidart setup, 5 modules (NO Core module), Contracts layer, Events infrastructure (3 events only)"
    status: completed
  - id: context-layer
    content: "3. Context Layer: RequestContext, TenantContext, ActorContext, Middleware, Exception handling, Tenant resolution strategy"
    status: completed
  - id: identity-auth
    content: "4. Identity & Auth: Users table, Auth scaffolding, Tenants table (NO settings JSON), Tenant membership (no role field)"
    status: in_progress
  - id: api-module
    content: "5. API Module: Versioning (v1/), Sanctum auth, Standard response format - BEFORE Frontend"
    status: pending
  - id: frontend
    content: "6. Frontend: Inertia + Vue 3 + Vuetify, AppShell layout, Auth pages, Dashboard - AFTER API"
    status: pending
  - id: settings
    content: "7. Settings Module: Central platform_settings only (no tenant/user settings in Phase 1)"
    status: pending
  - id: audit
    content: "8. Audit Module: audit_logs table, Auditable trait, Audit viewer with filters"
    status: pending
  - id: seeders-tests
    content: "9. Seeders & Testing: Admin user, Personal tenant, Membership, Smoke tests per module (5 modules)"
    status: pending
isProject: true
---

# Phase 1 - Technical Foundation (FINAL)

## Revision History

- **v1**: Original plan with 7 gaps
- **v2**: Fixed all 7 gaps (Events, Contracts, RBAC deferral, Exceptions, DB conventions, API, Testing)
- **v3 (FINAL)**: Applied reviewer feedback:
  1. Removed `tenants.settings` JSON (two sources of truth)
  2. Moved API module BEFORE Frontend in sequence
  3. Removed Core module (primitives stay in `app/Support/*`)
  4. Added explicit tenant resolution strategy
  5. Simplified events list (3 events only, removed `AuditLogCreated`)
  6. Removed `BaseMigration` (document conventions instead)
  7. Central settings only (no tenant/user settings in Phase 1)

---

## Governing Principles Alignment

| Principle | Coverage | Status |
|-----------|----------|--------|
| Domain code doesn't know isolation type | Architecture enforcement | OK |
| Tenant is always Context | TenantContext + resolution strategy | OK |
| Users = Central Identity | TODO 4 covers this | OK |
| Membership defines relationship | TODO 4.4 covers this | OK |
| Platform Billing != Tenant Commerce | OUT OF SCOPE | OK |
| Isolation level = operational choice | Field exists, not activated | OK |
| Analytics outside domain | OUT OF SCOPE | OK |
| Event-driven where possible | TODO 2.4 (3 events, dispatch-only) | OK |
| Start Shared -> expand when needed | Default Personal Tenant | OK |

---

## Scope Definition

### IN SCOPE

- Laravel 12 + PostgreSQL
- Modular Monolith (nWidart) - **5 modules (NO Core module)**
- Central vs Tenant Data separation (structural)
- Tenant Context with **explicit resolution strategy**
- Auth + Identity (Central)
- Membership model (tenant_users) - **without role field**
- **Central Settings only** (platform_settings table)
- Audit (Central)
- Events Infrastructure (dispatch-only, **3 events**)
- Contracts Layer
- Exception Handling
- Database Conventions (**documented, no BaseMigration**)
- Testing Foundation
- CI / Lint / Tests baseline
- Inertia + Vue 3 + Vuetify App shell

### OUT OF SCOPE

- Billing providers
- Payments gateways
- Webhooks
- Analytics implementation
- Enterprise SSO
- Advanced tenancy isolation (schema/db switching)
- RBAC/Permissions (moved to Phase 2)
- **Tenant-level settings** (moved to Phase 2+)
- **User preferences** (moved to Phase 2+)
- **Core module** (primitives in `app/Support/*` only)

---

## FINAL TODO Sequence

```
1. Repository & Tooling (DB conventions documented)
2. Modules Architecture (5 modules + Contracts + Events)
3. Context Layer (Middleware + Exceptions + Tenant Resolution)
4. Identity & Auth
5. API Module (BEFORE Frontend - error format needed first)
6. Frontend (AFTER API - can use error handling)
7. Settings (Central only)
8. Audit
9. Seeders & Testing
```

**Key sequence change**: API moved to position 5 (before Frontend) so error response format exists before building UI.

---

## Detailed TODO List

### 1. Repository and Tooling

#### TODO 1.1 - Project Bootstrap

- [ ] Create Laravel 12 project
- [ ] Configure PostgreSQL connection
- [ ] Enable UUID support (models + migrations)
- [ ] Setup `.env.example` with required variables

#### TODO 1.2 - Quality and CI

- [ ] Install Laravel Pint
- [ ] Configure Pint rules (PSR-12 + Laravel conventions)
- [ ] Setup PHPUnit with parallel testing
- [ ] Add CI pipeline (lint + tests)

#### TODO 1.3 - Database Conventions (Documented Only)

**Document in `docs/database-conventions.md`** (no BaseMigration class):

- Tables: `snake_case`, plural (`users`, `tenants`)
- Columns: `snake_case` (`created_at`, `tenant_id`)
- Foreign keys: `{table}_id` pattern
- All tables use UUID primary keys
- All tables have `created_at`, `updated_at`
- Tenant-owned tables have `tenant_id` (indexed)
- Soft deletes: `deleted_at` on domain tables

**Enforcement**: Code review + tests (not a base class)

#### TODO 1.4 - Testing Foundation

- [ ] Create `tests/Unit/Modules/` directory structure
- [ ] Create `tests/Feature/Modules/` directory structure
- [ ] Create `TestCase` with tenant helpers:
  - `actingAsTenant($tenant)`
  - `createPersonalTenant($user)`
  - `assertTenantScoped($model)`
- [ ] Document testing conventions in `tests/README.md`

---

### 2. Modular Architecture

#### TODO 2.1 - Modules System

- [ ] Install `nWidart/laravel-modules`
- [ ] Configure modules path (`Modules/`)
- [ ] Document rule: `app/` only for shared primitives (`app/Support/*`)
- [ ] Create module generation stub with required structure

#### TODO 2.2 - Modules (5 modules - NO Core module)

**IMPORTANT**: No `Core` module. Shared primitives live in `app/Support/*` only.

Create these 5 modules:

| Module | Purpose |
|--------|---------|
| Tenancy | Tenant management, context, membership |
| Identity | Users, authentication |
| Settings | Central platform settings |
| Audit | Audit logging |
| Api | API versioning, responses |

Each module structure:

```
Modules/{Name}/
├── Config/
├── Database/
│   ├── Migrations/
│   └── Seeders/
├── Models/
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Services/
├── Actions/
├── Events/
├── Listeners/
├── Exceptions/
├── Routes/
│   ├── web.php
│   └── api.php
└── Providers/
```

#### TODO 2.3 - Contracts Layer

Create in `app/Support/Contracts/`:

```
app/Support/Contracts/
├── Tenancy/
│   ├── TenantResolverInterface.php
│   └── TenantContextInterface.php
├── Settings/
│   └── SettingsRepositoryInterface.php
├── Audit/
│   └── AuditLoggerInterface.php
├── Context/
│   └── ContextProviderInterface.php
└── Events/
    └── DomainEventInterface.php
```

Key interfaces:

```php
interface TenantResolverInterface {
    public function resolve(): ?Tenant;
    public function resolveOrFail(): Tenant;
}

interface SettingsRepositoryInterface {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function forget(string $key): void;
}

interface AuditLoggerInterface {
    public function log(string $event, array $context = []): void;
}
```

#### TODO 2.4 - Events Infrastructure (Minimal - 3 Events Only)

Create in `app/Support/Events/`:

```
app/Support/Events/
├── DomainEvent.php          # Base class
├── Concerns/
│   └── HasTenantContext.php # Trait for tenant-aware events
└── Dispatcher/
    └── DomainEventDispatcher.php
```

Base DomainEvent class:

```php
abstract class DomainEvent implements DomainEventInterface
{
    public readonly string $eventId;
    public readonly DateTimeImmutable $occurredAt;
    public readonly ?string $tenantId;
    public readonly ?string $actorId;
    
    // Auto-captures context at creation time
}
```

**Phase 1 Events (dispatch-only, no listeners)**:

| Event | Module | When Dispatched |
|-------|--------|-----------------|
| `UserRegistered` | Identity | After user registration |
| `TenantCreated` | Tenancy | After tenant creation |
| `SettingUpdated` | Settings | After any setting change |

**NOT in Phase 1**: `AuditLogCreated` (removed - prevents circular logic, audit is internal log)

---

### 3. Context Layer

#### TODO 3.1 - Context Objects

Create in `app/Support/Context/`:

```
app/Support/Context/
├── RequestContext.php    # request_id, ip, user_agent
├── ActorContext.php      # user / system identifier
├── TenantContext.php     # current tenant
└── ExecutionContext.php  # web / job / cli / test
```

#### TODO 3.2 - Middleware

- [ ] `RequestContextMiddleware` - captures request metadata
- [ ] `TenantResolverMiddleware` - resolves and sets tenant context
- [ ] `ExecutionContextMiddleware` - sets execution mode

#### TODO 3.3 - Tenant Resolution Strategy (Phase 1)

**Explicit rule for how tenant is resolved in Phase 1**:

```
┌─────────────────────────────────────────────────────────────┐
│                  TENANT RESOLUTION (Phase 1)                 │
├─────────────────────────────────────────────────────────────┤
│ WEB REQUESTS:                                                │
│   1. Check session for 'active_tenant_id'                   │
│   2. If not set → use user's personal tenant (default)      │
│   3. Store resolved tenant in TenantContext singleton       │
│                                                              │
│ API REQUESTS:                                                │
│   1. Check Sanctum token abilities for 'tenant:{uuid}'      │
│   2. If not set → use user's personal tenant (default)      │
│   3. Store resolved tenant in TenantContext singleton       │
│                                                              │
│ JOBS/CLI:                                                    │
│   1. Tenant must be explicitly passed to job constructor    │
│   2. TenantContext set at job start                         │
│                                                              │
│ DEFAULT BEHAVIOR:                                            │
│   - New user registration → creates Personal Tenant         │
│   - Personal Tenant is always the fallback                  │
│   - No subdomain/domain resolution in Phase 1               │
└─────────────────────────────────────────────────────────────┘
```

Implementation:

```php
class TenantResolverMiddleware
{
    public function handle($request, $next)
    {
        $tenant = $this->resolver->resolve();
        
        if (!$tenant && auth()->check()) {
            // Fallback: user's personal tenant
            $tenant = auth()->user()->personalTenant();
        }
        
        if ($tenant) {
            TenantContext::set($tenant);
        }
        
        return $next($request);
    }
}
```

#### TODO 3.4 - Exception Handling

Create in `app/Exceptions/`:

```
app/Exceptions/
├── DomainException.php              # Base for all domain exceptions
├── Tenancy/
│   ├── TenantNotFoundException.php
│   ├── TenantAccessDeniedException.php
│   └── TenantContextMissingException.php
├── Identity/
│   └── UserNotFoundException.php
└── Handler.php                      # Custom exception handler
```

Exception response format (consistent for API + Web):

```php
// API Response
{
    "error": {
        "code": "TENANT_NOT_FOUND",
        "message": "The requested tenant does not exist",
        "details": {}  // Optional additional context
    }
}

// Web: Redirect with flash message or error page
```

---

### 4. Identity and Membership (Central)

#### TODO 4.1 - Users Table (Central)

Migration: `create_users_table`

```php
Schema::create('users', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();
    $table->softDeletes();
});
```

- [ ] User model with UUID trait
- [ ] User factory for testing

#### TODO 4.2 - Auth Scaffolding

- [ ] Login / Logout
- [ ] Password reset
- [ ] Email verification (optional, configurable)
- [ ] Session management
- [ ] Sanctum for API tokens

#### TODO 4.3 - Tenants Table (Central)

Migration: `create_tenants_table`

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('slug')->unique();
    $table->enum('type', ['personal', 'organization']);
    $table->enum('isolation_level', ['shared', 'schema', 'database'])
          ->default('shared');
    // NOTE: NO $table->json('settings') here!
    // Tenant settings belong in Settings module (Phase 2+)
    $table->timestamps();
    $table->softDeletes();
    
    $table->index('type');
    $table->index('isolation_level');
});
```

**IMPORTANT**: No `settings` JSON column on tenants table. This prevents "two sources of truth" conflict with Settings module.

- [ ] Tenant model
- [ ] Tenant factory
- [ ] `personalTenant()` method on User model (for resolution fallback)

#### TODO 4.4 - Tenant Membership

Migration: `create_tenant_users_table`

```php
Schema::create('tenant_users', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('user_id');
    $table->enum('membership_type', ['owner', 'admin', 'member', 'customer']);
    // NOTE: 'role' field DEFERRED to Phase 2 (RBAC)
    $table->enum('status', ['active', 'invited', 'suspended']);
    $table->timestamp('joined_at')->nullable();
    $table->timestamps();
    
    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->unique(['tenant_id', 'user_id']);
    $table->index('membership_type');
    $table->index('status');
});
```

- [ ] TenantUser (pivot) model
- [ ] Relations: User hasMany TenantUsers, Tenant hasMany TenantUsers
- [ ] Scopes: `activeMembers()`, `owners()`, `byMembershipType()`

**Decision: `role` field deferred to Phase 2** (GAP 3 resolution)

Reason: Without RBAC/permissions system, the `role` field has no meaning. Phase 2 will add:

- Spatie Permission package
- Role definitions
- Permission assignments
- `role` column to `tenant_users`

---

### 5. API Module (BEFORE Frontend)

**Rationale**: Frontend needs error response format to exist first.

#### TODO 5.1 - API Structure

- [ ] Configure API versioning: `/api/v1/`
- [ ] Setup Sanctum middleware for API routes
- [ ] Create API-specific middleware group
- [ ] Configure rate limiting

#### TODO 5.2 - API Response Format

Standard response wrapper (used by all API endpoints and Frontend error handling):

```php
// Success
{
    "data": { ... },
    "meta": {
        "request_id": "uuid",
        "timestamp": "ISO8601"
    }
}

// Error (consistent with Exception Handling)
{
    "error": {
        "code": "ERROR_CODE",
        "message": "Human readable message",
        "details": {}
    }
}

// Paginated
{
    "data": [ ... ],
    "meta": { ... },
    "pagination": {
        "current_page": 1,
        "per_page": 15,
        "total": 100
    }
}
```

- [ ] `ApiResponse` helper class
- [ ] `ApiController` base class with response methods
- [ ] Document API conventions in `docs/api-conventions.md`

---

### 6. Frontend Foundation (AFTER API)

#### TODO 6.1 - Inertia Stack

- [ ] Install Inertia.js (server-side adapter)
- [ ] Setup Vue 3 with Composition API
- [ ] Configure Vite
- [ ] Add Ziggy for route generation
- [ ] Configure SSR (optional, can defer)

#### TODO 6.2 - Vuetify Base

- [ ] Install Vuetify 3
- [ ] Configure theme (light/dark support)
- [ ] Create layouts:
  - `AppShell.vue` - Main authenticated layout
  - `AuthLayout.vue` - Login/register pages
  - `GuestLayout.vue` - Public pages

#### TODO 6.3 - Core Pages

- [ ] Dashboard page (with tenant context display)
- [ ] Login page
- [ ] Register page (creates Personal Tenant)
- [ ] Profile page

#### TODO 6.4 - Error Handling (Frontend)

- [ ] Use API error format for flash messages
- [ ] Global error handler component
- [ ] Toast/snackbar for API errors

---

### 7. Settings Module (Central Only)

**Phase 1 Decision**: Central platform settings only. Tenant and user settings deferred to Phase 2+.

#### TODO 7.1 - Platform Settings Table

Migration: `create_platform_settings_table`

```php
Schema::create('platform_settings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('group')->default('general');
    $table->string('key');
    $table->json('value')->nullable();
    $table->boolean('is_encrypted')->default(false);
    $table->uuid('updated_by')->nullable();
    $table->timestamps();
    
    $table->unique(['group', 'key']);
    $table->index('group');
});
```

**NOT in Phase 1**:
- No `scope` column (only system-level)
- No `scope_id` column
- No tenant settings
- No user preferences

#### TODO 7.2 - Settings Service

- [ ] `PlatformSettingsRepository` implementing `SettingsRepositoryInterface`
- [ ] `PlatformSettingsService` with:
  - `get($key, $default)`
  - `set($key, $value)` - dispatches `SettingUpdated` event
  - `forget($key)`
  - `getGroup($group)`
- [ ] Cache layer with tag-based invalidation
- [ ] Encryption for sensitive settings

#### TODO 7.3 - Settings UI (Minimal)

- [ ] System settings page (admin only)
- [ ] Settings list with edit capability

**Deferred to Phase 2+**:
- Tenant settings page
- User preferences page

---

### 8. Audit Module

#### TODO 8.1 - Audit Logs Table

Migration: `create_audit_logs_table`

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable();  // null for system events
    $table->uuid('actor_id')->nullable();   // user who performed action
    $table->string('actor_type')->default('user');  // user/system/job
    $table->string('event');                // e.g., 'user.created'
    $table->string('auditable_type');       // Model class
    $table->uuid('auditable_id');           // Model ID
    $table->json('old_values')->nullable();
    $table->json('new_values')->nullable();
    $table->json('metadata')->nullable();   // request_id, ip, etc.
    $table->timestamp('created_at');
    
    $table->index('tenant_id');
    $table->index('actor_id');
    $table->index('event');
    $table->index(['auditable_type', 'auditable_id']);
    $table->index('created_at');
});
```

#### TODO 8.2 - Auditable Trait

```php
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn($model) => $model->audit('created'));
        static::updated(fn($model) => $model->audit('updated'));
        static::deleted(fn($model) => $model->audit('deleted'));
    }
    
    public function audit(string $event): void
    {
        // Uses AuditLoggerInterface
    }
}
```

#### TODO 8.3 - Audit Viewer

- [ ] Audit list page with filters:
  - By tenant
  - By actor
  - By event type
  - By date range
  - By auditable model
- [ ] Audit detail view (diff viewer)

---

### 9. Seeders and Testing

#### TODO 9.1 - Seeders

- [ ] `AdminUserSeeder` - Creates super admin
- [ ] `PersonalTenantSeeder` - Creates personal tenant for admin
- [ ] `MembershipSeeder` - Links admin as owner
- [ ] `SystemSettingsSeeder` - Default system settings

#### TODO 9.2 - Dev Helpers

Create in `app/Support/Helpers/`:

```php
// Tenant helpers
function current_tenant(): ?Tenant;
function tenant(): Tenant;  // Throws if no context
function is_tenant_context(): bool;

// Actor helpers  
function current_actor(): ?User;
function actor(): User;  // Throws if no context

// Membership helpers
function membership(): ?TenantUser;
function is_owner(): bool;
function is_admin(): bool;
function membership_type(): ?string;
```

#### TODO 9.3 - Module Smoke Tests

Create at least 1 passing test per module (5 modules):

| Module | Test | Validates |
|--------|------|-----------|
| Tenancy | `TenantContextTest` | Context resolves, personal tenant fallback |
| Identity | `UserAuthTest` | Login/logout works, personal tenant created on register |
| Settings | `PlatformSettingsServiceTest` | Get/set works, cache invalidates |
| Audit | `AuditLoggingTest` | Audit entries created on model changes |
| Api | `ApiResponseTest` | Response format correct for success/error |

---

## Updated Definition of Done

Phase 1 is complete when ALL checks pass:

### Core Functionality

- [ ] User can register (creates Personal Tenant automatically)
- [ ] User can login/logout
- [ ] User is linked to Personal Tenant as owner
- [ ] TenantContext works in every request (session for web, token for API)
- [ ] Tenant resolution falls back to personal tenant correctly
- [ ] Platform settings can be saved, read, and cached
- [ ] Audit logs every create/update/delete on Auditable models

### Architecture

- [ ] No domain logic exists outside `Modules/`
- [ ] No "Core" module exists (primitives in `app/Support/*` only)
- [ ] All cross-module dependencies use Contracts (interfaces)
- [ ] 3 domain events dispatched (UserRegistered, TenantCreated, SettingUpdated)
- [ ] Exception handling returns consistent responses (API + Web)
- [ ] No `tenants.settings` JSON column exists

### Quality

- [ ] CI pipeline passes (lint + tests)
- [ ] At least 1 passing test per module (5 modules)
- [ ] Database conventions documented (not enforced via base class)
- [ ] API responses follow standard format

### Documentation

- [ ] `README.md` with setup instructions
- [ ] `tests/README.md` with testing conventions
- [ ] `docs/database-conventions.md` exists
- [ ] `docs/api-conventions.md` exists

---

## Files to Create

### Core Structure (app/Support/*)

- `app/Support/Contracts/` - All interfaces
  - `Tenancy/TenantResolverInterface.php`
  - `Tenancy/TenantContextInterface.php`
  - `Settings/SettingsRepositoryInterface.php`
  - `Audit/AuditLoggerInterface.php`
  - `Context/ContextProviderInterface.php`
  - `Events/DomainEventInterface.php`
- `app/Support/Context/` - Context objects
  - `RequestContext.php`
  - `ActorContext.php`
  - `TenantContext.php`
  - `ExecutionContext.php`
- `app/Support/Events/` - Event infrastructure
  - `DomainEvent.php`
  - `Concerns/HasTenantContext.php`
- `app/Support/Helpers/` - Helper functions
- `app/Exceptions/` - Custom exceptions
  - `DomainException.php`
  - `Tenancy/TenantNotFoundException.php`
  - `Tenancy/TenantAccessDeniedException.php`
  - `Tenancy/TenantContextMissingException.php`

### Modules (5 modules - NO Core)

- `Modules/Tenancy/`
- `Modules/Identity/`
- `Modules/Settings/`
- `Modules/Audit/`
- `Modules/Api/`

### Frontend

- `resources/js/Layouts/AppShell.vue`
- `resources/js/Layouts/AuthLayout.vue`
- `resources/js/Layouts/GuestLayout.vue`
- `resources/js/Pages/Dashboard.vue`
- `resources/js/Pages/Auth/Login.vue`
- `resources/js/Pages/Auth/Register.vue`

### Documentation

- `docs/database-conventions.md`
- `docs/api-conventions.md`
- `tests/README.md`

### Tests

- `tests/Unit/Modules/` - Unit tests
- `tests/Feature/Modules/` - Feature tests
- `tests/TestCase.php` - Base with tenant helpers

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| nWidart modules complexity | Start with skeleton, add gradually |
| Events overhead early | Dispatch only, no listeners yet (3 events) |
| Vuetify learning curve | Use component library, copy patterns |
| UUID performance | PostgreSQL native UUID, indexed |
| Settings scope creep | Central only in Phase 1, explicit boundary |

---

## Not in Phase 1 (Explicit Exclusions)

These are explicitly **deferred** to later phases:

| Item | Deferred To | Reason |
|------|-------------|--------|
| Core module | NEVER | Primitives in `app/Support/*` only |
| `tenants.settings` JSON | NEVER | Use Settings module instead |
| RBAC/Permissions | Phase 2 | Needs dedicated design |
| `role` field in tenant_users | Phase 2 | Meaningless without RBAC |
| Tenant settings | Phase 2+ | Control plane vs data plane separation |
| User preferences | Phase 2+ | Not core foundation |
| Billing/Subscriptions | Phase 5 | Not core foundation |
| Analytics events | Phase 6 | Out of scope |
| Schema/DB isolation | Phase 7 | Operational upgrade |
| SSO | Phase 7 | Enterprise feature |
| Subdomain/domain tenant resolution | Phase 3+ | Phase 1 uses session/token only |

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        PHASE 1 ARCHITECTURE                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    app/Support/* (Primitives)                │   │
│  │  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐            │   │
│  │  │ Contracts/  │ │  Context/   │ │  Events/    │            │   │
│  │  └─────────────┘ └─────────────┘ └─────────────┘            │   │
│  │  ┌─────────────┐ ┌─────────────┐                            │   │
│  │  │  Helpers/   │ │ Exceptions/ │                            │   │
│  │  └─────────────┘ └─────────────┘                            │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                       │
│                    implements contracts                              │
│                              ▼                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                      Modules/ (Domain)                       │   │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │   │
│  │  │ Tenancy  │ │ Identity │ │ Settings │ │  Audit   │       │   │
│  │  └──────────┘ └──────────┘ └──────────┘ └──────────┘       │   │
│  │  ┌──────────┐                                               │   │
│  │  │   Api    │                                               │   │
│  │  └──────────┘                                               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                       │
│                              ▼                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    Central Database                          │   │
│  │  users │ tenants │ tenant_users │ platform_settings │ audit │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```