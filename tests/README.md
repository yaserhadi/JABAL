# Testing Conventions

This document defines testing standards and practices for the Jabal SaaS Core Platform.

## Test Structure

```
tests/
├── Feature/
│   └── Modules/
│       ├── Tenancy/
│       ├── Identity/
│       ├── Settings/
│       ├── Audit/
│       └── Api/
├── Unit/
│   └── Modules/
│       ├── Tenancy/
│       ├── Identity/
│       ├── Settings/
│       ├── Audit/
│       └── Api/
└── TestCase.php
```

## Test Types

### Unit Tests

- **Location**: `tests/Unit/Modules/{ModuleName}/`
- **Purpose**: Test individual methods, classes, and functions in isolation
- **Database**: Should NOT use database (use mocks/fakes)
- **Speed**: Very fast (< 100ms per test)
- **Naming**: `{ClassName}Test.php`

**Example**:
```php
namespace Tests\Unit\Modules\Settings;

use PHPUnit\Framework\TestCase;

class PlatformSettingsServiceTest extends TestCase
{
    public function test_can_get_setting_with_default()
    {
        // Unit test - no database needed
    }
}
```

### Feature Tests

- **Location**: `tests/Feature/Modules/{ModuleName}/`
- **Purpose**: Test end-to-end workflows, API endpoints, full request/response cycles
- **Database**: Uses database (transactions rolled back after each test)
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

## Naming Conventions

### Test Method Names

- **Format**: `test_{what_it_does}`
- **Use snake_case**: Readable and descriptive
- **Be specific**: Describe the expected behavior

**Good**:
```php
test_user_can_register_with_valid_data()
test_personal_tenant_is_created_on_registration()
test_tenant_context_resolves_from_session()
test_setting_update_dispatches_event()
```

**Bad**:
```php
testRegister()
testLogin()
test_it_works()
test_1()
```

### Test Class Names

- **Format**: `{SubjectUnderTest}Test`
- **Examples**: `TenantContextTest`, `UserAuthTest`, `PlatformSettingsServiceTest`

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

## Custom Test Helpers

The base `TestCase` provides tenant-specific helpers:

### actingAsTenant($tenant)

Set the current tenant context for the test:

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

### createPersonalTenant($user)

Create a personal tenant for a user:

```php
public function test_personal_tenant_creation()
{
    $user = User::factory()->create();
    $tenant = $this->createPersonalTenant($user);
    
    $this->assertEquals('personal', $tenant->type);
}
```

### assertTenantScoped($model, $tenant)

Assert a model is properly scoped to a tenant:

```php
public function test_model_is_tenant_scoped()
{
    $tenant = Tenant::factory()->create();
    $model = MyModel::factory()->for($tenant)->create();
    
    $this->assertTenantScoped($model, $tenant);
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

## Module Smoke Tests

Each module must have at least one passing smoke test to verify the module loads correctly.

### Required Smoke Tests

| Module | Test File | Purpose |
|--------|-----------|---------|
| Tenancy | `TenantContextTest.php` | Verify tenant context resolution |
| Identity | `UserAuthTest.php` | Verify login/logout works |
| Settings | `PlatformSettingsServiceTest.php` | Verify get/set operations |
| Audit | `AuditLoggingTest.php` | Verify audit entries created |
| Api | `ApiResponseTest.php` | Verify response format |

### Example Smoke Test

```php
namespace Tests\Feature\Modules\Tenancy;

use Tests\TestCase;

class TenantContextTest extends TestCase
{
    public function test_module_loads()
    {
        // Smoke test - just verify no fatal errors
        $this->assertTrue(true);
    }
}
```

## Running Tests

### Run All Tests

```bash
php artisan test
```

### Run Parallel Tests

```bash
php artisan test --parallel
```

### Run Specific Test Suite

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

### Run Specific Test File

```bash
php artisan test tests/Feature/Modules/Identity/UserAuthTest.php
```

### Run Specific Test Method

```bash
php artisan test --filter test_user_can_login
```

### With Coverage

```bash
php artisan test --coverage
php artisan test --coverage-html reports/
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
3. Use in-memory database for SQLite tests (CI only)
4. Run tests in parallel: `php artisan test --parallel`
5. Cache expensive operations in `setUp()`

## Continuous Integration

Tests run automatically on:
- Every push to `main` or `develop`
- Every pull request

CI Pipeline runs:
1. **Lint**: Pint code style check
2. **Tests**: PHPUnit with PostgreSQL
3. **Coverage**: Upload to Codecov

See `.github/workflows/ci.yml` for full CI configuration.

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
