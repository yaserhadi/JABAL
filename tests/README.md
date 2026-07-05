# Testing Conventions

This document defines testing standards and practices for the Jabal SaaS Core Platform.

## âš  Test database isolation (required â€” read this first)

Tests use `RefreshDatabase`, which calls `migrate:fresh` and **drops every table** on
the central and tenant connections. Without isolation this destroys the dev databases.

This project enforces isolation in two independent layers:

1. **`phpunit.xml`** overrides `DB_DATABASE_CENTRAL` and `DB_DATABASE_TENANT` so test runs
   target `jabal_central_testing` and `jabal_tenant_shared_testing` only â€” never the dev DBs.
2. **`tests/TestCase.php::setUp()`** reads the env BEFORE the framework boots and throws
   a `RuntimeException` if either name does not end with `_testing`. This catches
   accidental `phpunit.xml` regressions before any migration runs.

### One-time setup for a new workstation

```bash
psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE "jabal_central_testing"'
psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE "jabal_tenant_shared_testing"'
```

### Stage 5B â€” dedicated tenant session attestation DBs (T-S5B-08)

`DatabasePerTenantSessionIsolationTest` requires two additional physical PostgreSQL databases (`_testing` suffix):

```text
jabal_tenant_dedicated_a_testing
jabal_tenant_dedicated_b_testing
```

One-time setup (outside PHPUnit transactions):

```bash
php tests/Support/ensure_dedicated_test_databases.php
```

The helper creates the databases (if missing) and ensures tenant-layer migrations exist on each dedicated connection. If the databases are absent, dedicated test classes skip with a message pointing to this script.

**BK-053** â€” `DedicatedStorageApiTokenTest`, `DedicatedStorageSessionRegistryTest`, `DedicatedStorageSecurityPolicyTest`, and `DedicatedStorageSecuritySettingsTest` use the same dedicated DB setup (`jabal_tenant_dedicated_a_testing` via `InteractsWithDedicatedTenantDatabase`).


### Verifying isolation is still in place

A test run should never modify dev data. To prove it, snapshot a value in your dev DB
before and after a test run â€” e.g. count of `platform_users` â€” and confirm it is unchanged.
For background on why this matters, see `.cursor/reports/TEST_DB_ISOLATION.md` (local agent workspace).

### Stage 2.5 â€” runtime session isolation tests

`phpunit.xml` sets `SESSION_DRIVER=array` by default. Tests that assert session **persistence**
in `central.platform_sessions` vs `tenant.sessions` (see `RuntimeSessionIsolationTest`) override
the driver to `database` in `setUp()`.

HTTP web runtime is selected by `ConfigureApplicationRuntime` (not `SESSION_CONNECTION`).
Env vars: `PLATFORM_SESSION_COOKIE`, `TENANT_SESSION_COOKIE` (see `.env.example`).

---

## Testing Overview

The Jabal platform uses **PHPUnit** with **Laravel** for testing. Tests are organized into two main categories:

- **Feature Tests**: Test complete workflows, HTTP requests/responses, and integration between components
- **Unit Tests**: Test individual classes, methods, and functions in isolation

Tests are organized by module to maintain clear boundaries and enable module-specific testing strategies.

### Test Structure

```
tests/
â”œâ”€â”€ Feature/
â”‚   â”œâ”€â”€ Modules/
â”‚   â”‚   â”œâ”€â”€ Tenancy/
â”‚   â”‚   â”œâ”€â”€ Identity/
â”‚   â”‚   â”œâ”€â”€ Settings/
â”‚   â”‚   â”œâ”€â”€ Audit/
â”‚   â”‚   â””â”€â”€ Api/
â”‚   â”œâ”€â”€ WorkspaceCrudTest.php        # Phase 3C: workspace CRUD, binding isolation, RBAC
â”‚   â””â”€â”€ TenantMemberManagementTest.php  # Phase 3C: member list, suspend, last-owner
â”œâ”€â”€ Unit/
â”‚   â””â”€â”€ Modules/
â”‚       â”œâ”€â”€ Tenancy/
â”‚       â”œâ”€â”€ Identity/
â”‚       â”œâ”€â”€ Settings/
â”‚       â”œâ”€â”€ Audit/
â”‚       â””â”€â”€ Api/
â””â”€â”€ TestCase.php
```

### Test Types

#### Feature Tests

- **Location**: `tests/Feature/Modules/{ModuleName}/`
- **Purpose**: Test end-to-end workflows, API endpoints, full request/response cycles
- **Database**: Uses database (transactions rolled back after each test via `RefreshDatabase`)
- **Speed**: Slower (< 500ms per test)
- **Naming**: `{FeatureName}Test.php`

**Example**:
```php
namespace Tests\Feature\Modules\Identity;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_user_can_login()
    {
        // Feature test - uses database
    }
}
```

#### Unit Tests

- **Location**: `tests/Unit/Modules/{ModuleName}/`
- **Purpose**: Test individual methods, classes, and functions in isolation
- **Database**: Should NOT use database (use mocks/fakes when possible)
- **Speed**: Very fast (< 100ms per test)
- **Naming**: `{ClassName}Test.php`

**Example**:
```php
namespace Tests\Unit\Modules\Settings;

use Tests\TestCase;

class PlatformSettingsServiceTest extends TestCase
{
    public function test_can_get_setting_with_default()
    {
        // Unit test - no database needed
    }
}
```

## Running Tests

### Run All Tests

```bash
php artisan test
```

### Run Specific Test

```bash
php artisan test --filter TenantContextTest
```

### Run With Coverage

```bash
php artisan test --coverage
```

### Additional Commands

```bash
# Run parallel tests (faster execution)
php artisan test --parallel

# Run specific test suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run specific test file
php artisan test tests/Feature/Modules/Identity/UserAuthTest.php

# Run specific test method
php artisan test --filter test_user_can_login

# Generate HTML coverage report
php artisan test --coverage-html reports/
```

## Test Helpers (in TestCase.php)

The base `TestCase` class (`tests/TestCase.php`) provides custom helper methods for tenant-related testing:

### `actingAsTenant($tenant)`

Set the current tenant context for the test. This method sets the tenant in the `TenantContext` singleton.

**Usage**:
```php
public function test_user_can_access_tenant_data()
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    
    $this->actingAs($user)
         ->actingAsTenant($tenant)
         ->get('/dashboard')
         ->assertOk();
}
```

**Implementation**:
```php
protected function actingAsTenant($tenant)
{
    TenantContext::getInstance()->set($tenant);
    return $this;
}
```

### `createPersonalTenant($user)`

Create a personal tenant for a user. This helper creates both the tenant and the `TenantUser` relationship with 'owner' membership.

**Usage**:
```php
public function test_personal_tenant_creation()
{
    $user = User::factory()->create();
    $tenant = $this->createPersonalTenant($user);
    
    $this->assertEquals('personal', $tenant->type);
    $this->assertDatabaseHas('tenant_users', [
        'user_id' => $user->id,
        'tenant_id' => $tenant->id,
        'membership_type' => 'owner',
    ]);
}
```

**Returns**: `\Modules\Tenancy\Models\Tenant` instance

### `assignDashboardViewToUser($user, $tenant)` (Phase 3B+)

Assign `dashboard.view` permission to a user in a tenant. Required for tests that hit `/api/v1/me` or `/t/{tenant}/dashboard`, which enforce `permission:dashboard.view`.

**Usage**:
```php
public function test_authenticated_user_can_access_me()
{
    $user = User::factory()->create();
    $tenant = $this->createPersonalTenant($user);
    $this->assignDashboardViewToUser($user, $tenant);

    $token = $user->createToken('test', ['tenant:'.$tenant->id])->plainTextToken;
    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $tenant->id,
    ])->getJson('/api/v1/me')->assertStatus(200);
}
```

**Returns**: `void`

### `assignWorkspaceRole($user, $tenant, $roleName)` (Phase 3C+)

Assign workspace and dashboard permissions to a user in a tenant. Used by `WorkspaceCrudTest` and similar tests that hit `/t/{tenant}/workspaces` or workspace API endpoints. Default role is `tenant-admin` (includes workspace.view, workspace.create, workspace.update, workspace.delete, dashboard.view).

**Usage**:
```php
$this->assignWorkspaceRole($this->userA, $this->tenantA);
$this->assignWorkspaceRole($this->userB, $this->tenantB, 'member');
```

**Returns**: `void`

**See also**: `TenantMemberManagementTest` uses `member.view`, `member.assign-role`, `member.suspend`; seed via `RbacCatalogSeeder` or create roles with those permissions.

### `assertTenantScoped($model, $tenant)`

Assert that a model is properly scoped to a tenant. If no tenant is provided, it uses the current tenant from `TenantContext`.

**Usage**:
```php
public function test_model_is_tenant_scoped()
{
    $tenant = Tenant::factory()->create();
    $model = MyModel::factory()->for($tenant)->create();
    
    $this->assertTenantScoped($model, $tenant);
}

// Or without explicit tenant (uses TenantContext)
public function test_model_uses_current_tenant()
{
    $tenant = Tenant::factory()->create();
    $this->actingAsTenant($tenant);
    
    $model = MyModel::factory()->create();
    
    $this->assertTenantScoped($model); // Uses tenant from context
}
```

## Writing Tests

### Basic Test Structure

All tests should follow this structure:

```php
namespace Tests\Feature\Modules\Identity;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Modules\Tenancy\Models\Tenant;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase; // Always use for feature tests
    
    public function test_user_can_register_with_valid_data()
    {
        // Arrange
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
        
        // Act
        $response = $this->post('/register', $data);
        
        // Assert
        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
        ]);
    }
}
```

### Key Practices

1. **Always use `RefreshDatabase` trait** for feature tests that need database access
2. **Use factories** for model creation instead of manual `create()` calls
3. **Test tenant context** when working with multi-tenant features
4. **Test auth flows** including login, logout, and registration
5. **Follow Arrange-Act-Assert pattern** for clarity

### Example Test Structure

```php
namespace Tests\Feature\Modules\Tenancy;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Modules\Tenancy\Models\Tenant;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_tenant_context_can_be_set_and_retrieved()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        
        // Act
        $this->actingAsTenant($tenant);
        
        // Assert
        $this->assertTrue(TenantContext::getInstance()->has());
        $this->assertEquals($tenant->id, TenantContext::getInstance()->get()->id);
    }
    
    public function test_user_can_access_tenant_scoped_data()
    {
        // Arrange
        $user = User::factory()->create();
        $tenant = $this->createPersonalTenant($user);
        
        // Act
        $this->actingAs($user)
             ->actingAsTenant($tenant)
             ->get('/dashboard');
        
        // Assert
        $this->assertTenantScoped($tenant, $tenant);
    }
}
```

## Conventions

### Test Method Naming

- **Format**: `test_description_of_behavior`
- **Use snake_case**: Readable and descriptive
- **Be specific**: Describe the expected behavior, not the implementation
- **One assertion per concept**: Each test should verify one specific behavior

**Good Examples**:
```php
test_user_can_register_with_valid_data()
test_personal_tenant_is_created_on_registration()
test_tenant_context_resolves_from_session()
test_setting_update_dispatches_event()
test_user_cannot_access_other_tenant_data()
```

**Bad Examples**:
```php
testRegister()                    // Too vague
testLogin()                       // Too vague
test_it_works()                   // Meaningless
test_1()                          // Not descriptive
test_user_registration()          // Describes action, not behavior
```

### Test Class Names

- **Format**: `{SubjectUnderTest}Test`
- **Examples**: `TenantContextTest`, `UserAuthTest`, `PlatformSettingsServiceTest`

### Clean Up After Tests

The `RefreshDatabase` trait automatically handles database cleanup after each test. No manual cleanup is needed in most cases:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase; // Handles cleanup automatically
    
    // No need for tearDown() in most cases
}
```

## Testing Traits

### RefreshDatabase

Use for tests that need database access:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase;
    
    // Database will be migrated and rolled back after each test
}
```

### WithFaker

Use for generating fake data:

```php
use Illuminate\Foundation\Testing\WithFaker;

class MyTest extends TestCase
{
    use WithFaker;
    
    public function test_example()
    {
        $name = $this->faker->name;
    }
}
```

## Factories

### Location

- Factories belong in: `Modules/{ModuleName}/Database/Factories/`
- Namespace: `Modules\{ModuleName}\Database\Factories`

### Example Factory

```php
namespace Modules\Tenancy\Database\Factories;

use Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;
    
    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'name' => $this->faker->company,
            'slug' => $this->faker->unique()->slug,
            'type' => 'organization',
            'isolation_level' => 'shared',
        ];
    }
    
    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'personal',
        ]);
    }
}
```

## Assertions

### Common Assertions

```php
// HTTP Status
$response->assertOk(); // 200
$response->assertCreated(); // 201
$response->assertNoContent(); // 204
$response->assertNotFound(); // 404
$response->assertForbidden(); // 403
$response->assertUnauthorized(); // 401

// JSON Structure
$response->assertJsonStructure([
    'data' => [
        'id',
        'name',
        'created_at',
    ],
]);

// Database
$this->assertDatabaseHas('users', ['email' => 'test@example.com']);
$this->assertDatabaseMissing('users', ['email' => 'deleted@example.com']);
$this->assertSoftDeleted($model);

// Events
Event::assertDispatched(UserRegistered::class);
Event::assertNotDispatched(SomeOtherEvent::class);

// Jobs
Queue::assertPushed(ProcessJob::class);
```

## Module-Specific Tests

Each module has specific test files that verify core functionality. These tests serve as both smoke tests and integration tests.

### Tenancy Module: `TenantContextTest`

**Location**: `tests/Feature/Modules/TenantContextTest.php`

Tests tenant context resolution and tenant-scoped data access:

```php
namespace Tests\Feature\Modules;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Modules\Tenancy\Models\Tenant;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_tenant_context_can_be_set_and_retrieved()
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsTenant($tenant);
        
        $this->assertTrue(TenantContext::getInstance()->has());
        $this->assertEquals($tenant->id, TenantContext::getInstance()->get()->id);
    }
    
    public function test_personal_tenant_fallback_for_user()
    {
        $user = User::factory()->create();
        $tenant = $this->createPersonalTenant($user);
        
        $this->actingAs($user);
        $this->actingAsTenant($tenant);
        
        $this->assertNotNull($user->personalTenant());
        $this->assertEquals($tenant->id, $user->personalTenant()->id);
    }
}
```

### Identity Module: `UserAuthTest`

**Location**: `tests/Feature/Modules/UserAuthTest.php`

Tests authentication flows including login and logout:

```php
namespace Tests\Feature\Modules;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_login_redirects_to_dashboard()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->createPersonalTenant($user);
        
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }
    
    public function test_logout_clears_session()
    {
        $user = User::factory()->create();
        $this->createPersonalTenant($user);
        
        $response = $this->actingAs($user)->post(route('logout'));
        
        $response->assertRedirect('/');
        $this->assertGuest();
    }
}
```

### Settings Module: `PlatformSettingsServiceTest`

**Location**: `tests/Unit/Modules/PlatformSettingsServiceTest.php`

Tests platform settings service get/set operations:

```php
namespace Tests\Unit\Modules;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Settings\Services\PlatformSettingsService;

class PlatformSettingsServiceTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_get_and_set_work()
    {
        $service = app(PlatformSettingsService::class);
        
        $this->assertNull($service->get('test_key'));
        $this->assertEquals('default', $service->get('test_key', 'default'));
        
        $service->set('test_key', 'test_value');
        
        $this->assertEquals('test_value', $service->get('test_key'));
    }
    
    public function test_has_and_forget_work()
    {
        $service = app(PlatformSettingsService::class);
        
        $this->assertFalse($service->has('test_key'));
        $service->set('test_key', 'value');
        $this->assertTrue($service->has('test_key'));
        $service->forget('test_key');
        $this->assertFalse($service->has('test_key'));
    }
}
```

### Audit Module: `AuditLoggingTest`

**Location**: `tests/Unit/Modules/AuditLoggingTest.php`

Tests audit logging functionality:

```php
namespace Tests\Unit\Modules;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Services\AuditLogger;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_log_creates_audit_entry()
    {
        $logger = app(AuditLoggerInterface::class);
        
        $logger->log('test.event', [
            'auditable_type' => 'TestModel',
            'auditable_id' => 'test-id',
            'new_values' => ['foo' => 'bar'],
        ]);
        
        $this->assertDatabaseCount('audit_logs', 1);
        $log = AuditLog::first();
        $this->assertEquals('test.event', $log->event);
        $this->assertEquals('TestModel', $log->auditable_type);
    }
}
```

### Api Module: `ApiResponseTest`

**Location**: `tests/Feature/Modules/ApiResponseTest.php`

Tests API response format and structure:

```php
namespace Tests\Feature\Modules;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class ApiResponseTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_api_returns_standard_success_format_when_authenticated()
    {
        $user = User::factory()->create();
        $this->createPersonalTenant($user);
        $token = $user->createToken('test')->plainTextToken;
        
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apis');
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'request_id',
                'timestamp',
            ],
        ]);
    }
}
```

## Test Data Best Practices

### 1. Use Factories

```php
// Good
$user = User::factory()->create();

// Bad
$user = User::create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    // ... many fields
]);
```

### 2. Use Specific Factories When Needed

```php
$personalTenant = Tenant::factory()->personal()->create();
$orgTenant = Tenant::factory()->create(['type' => 'organization']);
```

### 3. Clean Up After Tests

```php
// RefreshDatabase handles this automatically
use RefreshDatabase;

// Or manually in tearDown
protected function tearDown(): void
{
    // Clean up code
    parent::tearDown();
}
```

### 4. Avoid Hard-Coded IDs

```php
// Good - use relationships
$tenant = Tenant::factory()->create();
$user = User::factory()->for($tenant)->create();

// Bad - hard-coded IDs
$user = User::factory()->create(['tenant_id' => 1]);
```

## Performance Guidelines

- **Unit tests**: Should run in < 100ms
- **Feature tests**: Should run in < 500ms
- **Total test suite**: Should complete in < 2 minutes

### Tips for Fast Tests

1. Use `RefreshDatabase` instead of `DatabaseMigrations`
2. Mock external services (mail, HTTP, etc.)
3. Use in-memory database for SQLite tests when applicable
4. Run tests in parallel: `php artisan test --parallel`
5. Cache expensive operations in `setUp()`

## Running Tests

Tests are run manually. No CI/CD automation exists in this repository (per PROJECT_MANIFEST Hard Constraints).

To run locally:
- **Lint**: `./vendor/bin/pint --test`
- **Tests**: `php artisan test --parallel` (requires PostgreSQL configured in `.env`)

## Test Coverage Goals

- **Minimum**: 60% overall coverage
- **Target**: 80% overall coverage
- **Critical paths**: 100% coverage (auth, tenant resolution, billing)

## When to Write Tests

### Always Write Tests For

- New features
- Bug fixes (write failing test first, then fix)
- Refactoring (ensure behavior unchanged)
- Critical paths (auth, payments, data access)

### Optional Tests For

- Simple getters/setters
- Framework code (Laravel already tested)
- Private methods (test through public interface)

## Example Test Workflow

```php
namespace Tests\Feature\Modules\Identity;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Models\User;
use Modules\Tenancy\Models\Tenant;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_user_can_register_with_valid_data()
    {
        // Arrange
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
        
        // Act
        $response = $this->post('/register', $data);
        
        // Assert
        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
        ]);
        
        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user->personalTenant());
    }
    
    public function test_personal_tenant_is_created_on_registration()
    {
        // Arrange
        $user = User::factory()->create();
        
        // Act
        $tenant = $this->createPersonalTenant($user);
        
        // Assert
        $this->assertEquals('personal', $tenant->type);
        $this->assertEquals($user->name, $tenant->name);
        $this->assertDatabaseHas('tenant_users', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'membership_type' => 'owner',
        ]);
    }
}
```

## References

- [Laravel Testing Documentation](https://laravel.com/docs/11.x/testing)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Test-Driven Development (TDD)](https://martinfowler.com/bliki/TestDrivenDevelopment.html)

