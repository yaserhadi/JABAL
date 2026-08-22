<?php

namespace Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\ProductCatalogService;

/**
 * WAVE-6 minimum Platform UI / API for Product catalog + Offerings.
 */
class PlatformCatalogController extends Controller
{
    public function index(): InertiaResponse|JsonResponse
    {
        app(ProductCatalogService::class)->ensureDefaultCatalog();

        $products = Product::query()
            ->with('capabilities')
            ->orderBy('code')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'capabilities' => $p->capabilities->map(fn (Capability $c) => [
                    'code' => $c->code,
                    'name' => $c->name,
                    'entitlement_code' => $c->entitlement_code,
                ])->values()->all(),
            ]);

        $offerings = Offering::query()
            ->with(['product', 'capabilities', 'plan'])
            ->orderBy('code')
            ->get()
            ->map(fn (Offering $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'status' => $o->status,
                'version' => $o->version,
                'product_code' => $o->product?->code,
                'plan_code' => $o->plan?->code,
                'capabilities' => $o->capabilities->pluck('code')->values()->all(),
            ]);

        $payload = ['products' => $products, 'offerings' => $offerings];

        if (request()->expectsJson()) {
            return ApiResponse::success($payload);
        }

        return Inertia::render('Platform/Catalog/Index', $payload);
    }
}
