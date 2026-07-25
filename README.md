# Jabal SaaS Core Platform

> **Phase 1 - Technical Foundation**

A modern, modular SaaS platform built on Laravel 11 with multi-tenancy support, designed for scalability and maintainability.

## Project Overview

Jabal is a SaaS Core Platform that provides a solid technical foundation for building multi-tenant applications. The platform emphasizes:

- **Modular Architecture**: Domain-driven design with nWidart modules
- **Multi-Tenancy**: Central identity with tenant-scoped data separation
- **Event-Driven**: Foundation for event-driven architecture
- **Modern Stack**: Laravel 11, Vue 3, Inertia.js, and PostgreSQL

## Tech Stack

- **Backend**: Laravel 11
- **Database**: PostgreSQL 14+
- **Frontend**: Vue 3 + Inertia.js + Vuetify 3
- **Architecture**: Modular Monolith (nWidart/laravel-modules)
- **Authentication**: Laravel Sanctum (API) + Session (Web)
- **Code Quality**: Laravel Pint (PSR-12), PHPUnit

## Requirements

- PHP 8.2 or higher
- PostgreSQL 14 or higher
- Composer 2.x
- Node.js 18+ and NPM
- Git

## Installation

### 1. Clone Repository

```bash
git clone <repository-url>
cd Jabal
```

### 2. Environment Setup

Copy the environment example file and configure your database:

```bash
cp .env.example .env
```

Edit `.env` and configure your database connection:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=jabal
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 3. Install Dependencies

Install PHP dependencies:

```bash
composer install
```

Install Inertia.js and Ziggy for route generation:

```bash
composer require inertiajs/inertia-laravel tightenco/ziggy
```

Install Node.js dependencies:

```bash
npm install
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Run Migrations

```bash
php artisan migrate
```

### 6. Seed Database

```bash
php artisan db:seed
```

This will create:
- Admin user
- Personal tenant for admin
- Default system settings

### 7. Build Frontend Assets

For development:

```bash
npm run dev
```

For production:

```bash
# Ensure composer install has run first (ziggy:generate requires Laravel)
composer install
npm run build
```

### 8. Start Development Server

In one terminal, start Laravel:

```bash
php artisan serve
```

In another terminal (if using `npm run dev`), the Vite dev server will run automatically.

Visit `http://localhost:8000` in your browser.

## Development

### Running Tests

Run all tests:

```bash
php artisan test
```

Run tests in parallel:

```bash
php artisan test --parallel
```

Run specific test suite:

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

Run specific test file:

```bash
php artisan test tests/Feature/Modules/Identity/UserAuthTest.php
```

### Code Style

Laravel Pint is configured for code style. Run:

```bash
./vendor/bin/pint
```

To check without fixing:

```bash
./vendor/bin/pint --test
```

### Development Workflow

1. **Backend Development**: 
   - Run `php artisan serve` for Laravel
   - Code changes auto-reload

2. **Frontend Development**:
   - Run `npm run dev` for Vite hot-reload
   - Vue components auto-reload on save

3. **Database Changes**:
   - Create migrations: `php artisan make:migration create_table_name`
   - Run migrations: `php artisan migrate`
   - Rollback: `php artisan migrate:rollback`

## Architecture

### Module Structure

The platform uses a modular monolith architecture with 5 core modules:

| Module | Purpose |
|--------|---------|
| **Tenancy** | Tenant management, context resolution, membership |
| **Identity** | User authentication, registration, session management |
| **Settings** | Central platform settings (key-value store) |
| **Audit** | Audit logging for model changes |
| **Api** | API versioning, standard response format, middleware |

### Data Separation

- **Central Data**: Users, Tenants, Tenant Users, Platform Settings, Audit Logs
- **Tenant-Scoped Data**: All domain data includes `tenant_id` for isolation

### Event-Driven Foundation

Phase 1 implements event infrastructure with 3 core events:
- `UserRegistered` - Dispatched after user registration
- `TenantCreated` - Dispatched after tenant creation
- `SettingUpdated` - Dispatched after setting changes

### Testing Conventions

- **Unit Tests**: `tests/Unit/Modules/{ModuleName}/` - Fast, no database
- **Feature Tests**: `tests/Feature/Modules/{ModuleName}/` - Full request/response cycles
- Each module has at least one smoke test

See [tests/README.md](tests/README.md) for detailed testing guidelines.

## Documentation

- **[Database Conventions](.cursor/memory/conventions/database-conventions.md)**: Naming, UUID usage, indexing, tenant scoping
- **[API Conventions](.cursor/memory/conventions/api-conventions.md)**: Response format, versioning, authentication
- **[Testing Guide](tests/README.md)**: Test structure, conventions, best practices
- Tenant addressing architecture: `.cursor/memory/decisions/DEC-0023-tenant-addressing-domain-resolution.md` (agents; not a public docs/ path)
- Optional local Host lab tips (advisory): `.cursor/memory/conventions/tenancy-addressing-local.md`

## Phase 1 Status

### ✅ Completed

- Repository setup with Laravel 11 + PostgreSQL
- Modular architecture (nWidart) with 5 modules
- Context layer (Request, Actor, Tenant, Execution)
- Identity & Authentication (Users, Sessions, Sanctum)
- API Module (Versioning, Standard Responses)
- Frontend Foundation (Inertia + Vue 3 + Vuetify)
- Settings Module (Central platform settings)
- Audit Module (Auditable trait, logging)
- Database conventions documentation
- Testing foundation with helpers
- CI/CD pipeline

### 🔄 In Progress

- Additional feature tests
- Frontend pages refinement

### 📋 Phase 1 Definition of Done

- ✅ User can register (creates Personal Tenant automatically)
- ✅ User can login/logout
- ✅ User is linked to Personal Tenant as owner
- ✅ TenantContext works in every request
- ✅ Tenant resolution falls back to personal tenant
- ✅ Platform settings can be saved, read, and cached
- ✅ Audit logs every create/update/delete on Auditable models
- ✅ No domain logic exists outside `Modules/`
- ✅ All cross-module dependencies use Contracts
- ✅ 3 domain events dispatched
- ✅ Exception handling returns consistent responses
- ✅ CI pipeline passes (lint + tests)
- ✅ At least 1 passing test per module
- ✅ Database conventions documented
- ✅ API responses follow standard format

## Project Structure

```
Jabal/
├── app/                    # Shared primitives only
│   ├── Support/           # Contracts, Context, Events, Helpers
│   └── Exceptions/        # Custom exceptions
├── Modules/                # Domain modules
│   ├── Tenancy/           # Tenant management
│   ├── Identity/          # Authentication
│   ├── Settings/          # Platform settings
│   ├── Audit/             # Audit logging
│   └── Api/               # API infrastructure
├── database/
│   ├── migrations/        # Database migrations
│   └── seeders/           # Database seeders
├── resources/
│   ├── js/                # Vue 3 + Inertia.js frontend
│   └── views/            # Blade templates
├── tests/                 # PHPUnit tests
│   ├── Unit/             # Unit tests
│   └── Feature/          # Feature tests
└── .cursor/               # Agent SSOT (memory, plans, rules — local/gitignored)
```

## Agent / development knowledge

Execution authority for AI agents and architects lives in **`.cursor/`** (see `.cursor/memory/HANDOFF.md` and `AGENT_CONSTITUTION.md`). Legacy `docs/` was migrated and removed (Phase B, 2026-06-16). `docs_status: deleted` remains binding; Phase C human-facing docs are **not** activated.

## Contributing

1. Follow database conventions: [.cursor/memory/conventions/database-conventions.md](.cursor/memory/conventions/database-conventions.md)
2. Follow API conventions: [.cursor/memory/conventions/api-conventions.md](.cursor/memory/conventions/api-conventions.md)
3. Write tests for new features
4. Run Pint before committing: `./vendor/bin/pint`
5. Ensure all tests pass: `php artisan test`

## License

This project is proprietary software. All rights reserved.

## Support

For questions or issues, refer to `.cursor/memory/HANDOFF.md` (local agent workspace) or create an issue in the project repository.
