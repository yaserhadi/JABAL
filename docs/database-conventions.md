# Database Conventions

This document defines the database naming and structure conventions for the Jabal SaaS Core Platform.

## Table Naming

- **Format**: `snake_case`, plural
- **Examples**: `users`, `tenants`, `tenant_users`, `platform_settings`, `audit_logs`
- **Rule**: Tables represent collections of entities, so use plural names

## Column Naming

- **Format**: `snake_case`
- **Examples**: `created_at`, `tenant_id`, `email_verified_at`, `membership_type`
- **Timestamps**: Always use `created_at`, `updated_at` (not `createdAt` or `created`)
- **Booleans**: Prefix with `is_`, `has_`, or `can_` (e.g., `is_encrypted`, `has_access`)
- **JSON columns**: Suffix with context if needed (e.g., `settings`, `metadata`, `old_values`)

## Foreign Keys

- **Format**: `{table}_id`
- **Examples**: `user_id`, `tenant_id`, `actor_id`
- **Rule**: Foreign keys should reference the singular form of the related table followed by `_id`
- **Always indexed**: Foreign key columns must have an index for query performance

## Primary Keys

- **Type**: UUID (Universally Unique Identifier)
- **Column name**: `id`
- **Format**: `uuid` data type in PostgreSQL
- **Migration**: Use `$table->uuid('id')->primary();`
- **Models**: Use `HasUuids` trait or `Str::uuid()` in boot method

## Standard Columns

All tables must include these columns:

```php
$table->uuid('id')->primary();
$table->timestamps(); // creates created_at and updated_at
```

### Optional Standard Columns

- **Soft Deletes**: `$table->softDeletes();` - adds `deleted_at` column
  - Use for domain tables where data should be preserved
  - Do not use for pivot tables or logs
- **User Tracking**: `updated_by`, `created_by` (UUID foreign keys)

## Tenant-Scoped Tables

Tables that belong to a tenant must include:

```php
$table->uuid('tenant_id');
$table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
$table->index('tenant_id'); // Critical for query performance
```

**Tenant-scoped tables examples**:
- Any domain data that belongs to a specific tenant
- Customer-facing data
- Business-specific configurations

**NOT tenant-scoped**:
- `users` (Central identity table)
- `tenants` (Central registry)
- `platform_settings` (Platform-level configuration)
- `audit_logs` (Central audit, has nullable tenant_id)

## Indexes

### Required Indexes

1. **Primary key**: Always indexed automatically
2. **Foreign keys**: Always create index on foreign key columns
3. **Tenant ID**: Always indexed on tenant-scoped tables
4. **Unique constraints**: Indexed automatically

### Optional Indexes

Add indexes for:
- Columns frequently used in WHERE clauses
- Columns used in ORDER BY
- Columns used in JOIN conditions
- High-cardinality columns with frequent searches

**Example**:
```php
$table->index('email'); // If email is searched frequently
$table->index('status'); // If status is filtered
$table->index(['tenant_id', 'created_at']); // Composite index for tenant-scoped date queries
```

## Unique Constraints

- Format: `$table->unique(['column1', 'column2']);`
- Use composite unique constraints for scoped uniqueness

**Examples**:
```php
// Email unique per tenant
$table->unique(['tenant_id', 'email']);

// Slug unique per tenant  
$table->unique(['tenant_id', 'slug']);

// Single column unique (global)
$table->unique('email'); // For users table
```

## Data Types

### Common Types

| Use Case | Laravel Method | PostgreSQL Type |
|----------|---------------|-----------------|
| Primary Key | `uuid('id')->primary()` | UUID |
| Foreign Key | `uuid('column_name')` | UUID |
| Short Text | `string('column')` | VARCHAR(255) |
| Long Text | `text('column')` | TEXT |
| Integer | `integer('column')` | INTEGER |
| Big Integer | `bigInteger('column')` | BIGINT |
| Boolean | `boolean('column')` | BOOLEAN |
| Decimal | `decimal('column', 10, 2)` | DECIMAL(10,2) |
| Date | `date('column')` | DATE |
| Timestamp | `timestamp('column')` | TIMESTAMP |
| JSON | `json('column')` | JSON |
| Enum | `enum('column', [...])` | Custom ENUM or VARCHAR |

### JSON Columns

Use JSON for:
- Flexible settings/preferences
- Audit trail data (old/new values)
- Dynamic metadata

**Example**:
```php
$table->json('metadata')->nullable();
$table->json('settings')->nullable();
```

## Enum Columns

**Phase 1 Approach**: Use string enums (Laravel 11+)

```php
$table->enum('status', ['active', 'invited', 'suspended']);
$table->enum('membership_type', ['owner', 'admin', 'member', 'customer']);
$table->enum('type', ['personal', 'organization']);
```

**Benefits**:
- Database-level constraint
- Clear allowed values
- Better than separate lookup tables for small, stable sets

## Soft Deletes

Use soft deletes for:
- Domain entities (Users, Tenants, etc.)
- Any data that should be preserved for audit/recovery
- Tables where "deletion" means "archived" not "destroyed"

**Do NOT use soft deletes for**:
- Pivot tables
- Log tables
- Cache tables
- Session tables

```php
// Enable soft deletes
$table->softDeletes();

// In model
use Illuminate\Database\Eloquent\SoftDeletes;

class MyModel extends Model
{
    use SoftDeletes;
}
```

## Migration File Naming

- **Format**: `YYYY_MM_DD_HHMMSS_create_table_name_table.php`
- **Laravel Default**: Timestamp-based (automatic)
- **Example**: `2026_01_30_120000_create_tenants_table.php`

## Example Migration (Complete)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();
            
            // Foreign keys
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            
            // Domain columns
            $table->enum('membership_type', ['owner', 'admin', 'member', 'customer']);
            $table->enum('status', ['active', 'invited', 'suspended']);
            $table->timestamp('joined_at')->nullable();
            
            // Standard columns
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            
            // Unique constraints
            $table->unique(['tenant_id', 'user_id']);
            
            // Indexes
            $table->index('membership_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
```

## Enforcement

These conventions are enforced through:
1. **Code Review**: All migration PRs must follow these conventions
2. **Tests**: Database tests verify UUID usage and proper indexing
3. **Documentation**: This document is the single source of truth
4. **NOT**: Base migration class (too rigid, prefer review + tests)

## Rationale

### Why UUID?

- **Distributed Systems**: Safe to generate IDs without central coordination
- **Security**: Non-sequential, harder to enumerate
- **Multi-Tenant**: Prevents cross-tenant ID collisions
- **Merging**: Safe to merge data from different sources

### Why snake_case?

- **PostgreSQL Convention**: Aligns with database naming standards
- **Clarity**: More readable than camelCase in SQL queries
- **Laravel Default**: Framework convention

### Why Plural Table Names?

- **Rails/Laravel Convention**: Industry standard
- **Semantic**: Tables represent collections

## References

- [Laravel Migrations Documentation](https://laravel.com/docs/11.x/migrations)
- [PostgreSQL Naming Conventions](https://www.postgresql.org/docs/current/sql-syntax-lexical.html#SQL-SYNTAX-IDENTIFIERS)
- [Ramsey UUID for Laravel](https://github.com/ramsey/uuid)
