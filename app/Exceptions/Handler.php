<?php

namespace App\Exceptions;

use App\Exceptions\Identity\UserNotFoundException;
use App\Exceptions\Tenancy\TenantAccessDeniedException;
use App\Exceptions\Tenancy\TenantContextMissingException;
use App\Exceptions\Tenancy\TenantNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Modules\Api\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global exception handler for consistent error responses.
 *
 * Handles custom domain exceptions with appropriate HTTP status codes
 * and consistent JSON/HTML response formats.
 */
class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  Request  $request
     * @param  Throwable  $e
     * @return Response
     *
     * @throws Throwable
     */
    public function render($request, Throwable $e)
    {
        // Handle DomainException and its subclasses
        if ($e instanceof DomainException) {
            return $this->renderDomainException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * Render a DomainException with consistent API/Web responses.
     *
     * @param  Request  $request
     * @param  DomainException  $e
     * @return Response
     */
    protected function renderDomainException(Request $request, DomainException $e): Response
    {
        // Determine HTTP status code based on exception type
        $statusCode = $this->getStatusCodeForException($e);

        // API requests: return JSON response
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->renderApiException($e, $statusCode);
        }

        // Web requests: redirect with flash message or show error page
        return $this->renderWebException($request, $e, $statusCode);
    }

    /**
     * Render exception as API JSON response.
     *
     * @param  DomainException  $e
     * @param  int  $statusCode
     * @return Response
     */
    protected function renderApiException(DomainException $e, int $statusCode): Response
    {
        // Use ApiResponse helper if available, otherwise use DomainException's toArray()
        if (class_exists(ApiResponse::class)) {
            return ApiResponse::error(
                $e->errorCode(),
                $e->getMessage(),
                $e->errorDetails(),
                $statusCode
            );
        }

        // Fallback: use DomainException's toArray() method
        return response()->json(
            $e->toArray(),
            $statusCode
        );
    }

    /**
     * Render exception as Web HTML response.
     *
     * @param  Request  $request
     * @param  DomainException  $e
     * @param  int  $statusCode
     * @return Response
     */
    protected function renderWebException(Request $request, DomainException $e, int $statusCode): Response
    {
        // If request has session, redirect back with flash message
        if ($request->hasSession()) {
            return back()
                ->withInput()
                ->withErrors([
                    'error' => $e->getMessage(),
                ])
                ->with('error_code', $e->errorCode());
        }

        // Fallback: return error view or simple error response
        if (view()->exists('errors.custom')) {
            return response()->view('errors.custom', [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'details' => $e->errorDetails(),
            ], $statusCode);
        }

        // Final fallback: simple error response
        return response($e->getMessage(), $statusCode);
    }

    /**
     * Get HTTP status code for exception type.
     *
     * @param  DomainException  $e
     * @return int
     */
    protected function getStatusCodeForException(DomainException $e): int
    {
        // Use exception's code if set and valid HTTP status code
        $code = $e->getCode();
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        // Map exception types to status codes
        return match (true) {
            $e instanceof UserNotFoundException => 404,
            $e instanceof TenantNotFoundException => 404,
            $e instanceof TenantAccessDeniedException => 403,
            $e instanceof TenantContextMissingException => 500,
            default => 500,
        };
    }
}
