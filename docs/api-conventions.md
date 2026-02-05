# API Conventions (Phase 1)

## Versioning

- All API routes are under `/api/v1/`.
- Version prefix is applied in the Api module route configuration.

## Authentication

- API requests use Laravel Sanctum (`auth:sanctum` middleware).
- Token abilities may include `tenant:{uuid}` for tenant-scoped access.
- Unauthenticated requests receive `401` with standard error format.

## Response Format

### Success

```json
{
  "data": { ... },
  "meta": {
    "request_id": "uuid",
    "timestamp": "ISO8601"
  }
}
```

### Error (aligned with DomainException)

```json
{
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message",
    "details": {}
  },
  "meta": {
    "request_id": "uuid",
    "timestamp": "ISO8601"
  }
}
```

### Paginated

```json
{
  "data": [ ... ],
  "meta": { "request_id": "...", "timestamp": "..." },
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 100,
    "last_page": 7
  }
}
```

## Usage

- Controllers extend `Modules\Api\Http\Controllers\BaseApiController`.
- Use `$this->success($data)`, `$this->error($code, $message, $details, $status)`, and `$this->paginated($data, $pagination)` for consistent responses.
- For delete: return `response()->noContent(204)`.
