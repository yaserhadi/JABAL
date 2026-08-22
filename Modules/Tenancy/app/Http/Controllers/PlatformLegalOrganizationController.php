<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Tenancy\Models\LegalOrganization;
use Modules\Tenancy\Services\LegalOrganizationService;

class PlatformLegalOrganizationController extends Controller
{
    public function __construct(
        private readonly LegalOrganizationService $legalOrganizations
    ) {}

    public function index(): InertiaResponse|JsonResponse
    {
        $orgs = LegalOrganization::query()
            ->withCount(['tenants', 'businessOwners'])
            ->orderBy('name')
            ->get()
            ->map(fn (LegalOrganization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'status' => $o->status,
                'tenants_count' => $o->tenants_count,
                'business_owners_count' => $o->business_owners_count,
            ]);

        if (request()->expectsJson()) {
            return ApiResponse::success(['legal_organizations' => $orgs]);
        }

        return Inertia::render('Platform/LegalOrganizations/Index', [
            'legal_organizations' => $orgs,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $org = $this->legalOrganizations->create(
            $data['name'],
            null,
            $request->user('platform')?->id
        );

        return ApiResponse::success(['legal_organization' => [
            'id' => $org->id,
            'name' => $org->name,
            'status' => $org->status,
        ]], 201);
    }

    public function show(string $legalOrganization): InertiaResponse|JsonResponse
    {
        $org = LegalOrganization::query()
            ->with(['tenants:id,name,slug,status,legal_organization_id', 'businessOwners'])
            ->findOrFail($legalOrganization);

        $payload = [
            'legal_organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'status' => $org->status,
                'tenants' => $org->tenants,
                'business_owners' => $org->businessOwners->map(fn ($o) => [
                    'id' => $o->id,
                    'user_id' => $o->user_id,
                    'primary_tenant_id' => $o->primary_tenant_id,
                    'status' => $o->status,
                    'assigned_at' => $o->assigned_at?->toIso8601String(),
                ]),
            ],
        ];

        if (request()->expectsJson()) {
            return ApiResponse::success($payload);
        }

        return Inertia::render('Platform/LegalOrganizations/Show', $payload);
    }

    public function assignOwner(Request $request, string $legalOrganization): JsonResponse
    {
        $org = LegalOrganization::query()->findOrFail($legalOrganization);
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'primary_tenant_id' => ['nullable', 'uuid'],
        ]);

        $owner = $this->legalOrganizations->assignBusinessOwner(
            $org,
            $data['user_id'],
            $data['primary_tenant_id'] ?? null,
            $request->user('platform')?->id
        );

        return ApiResponse::success([
            'business_owner' => [
                'id' => $owner->id,
                'user_id' => $owner->user_id,
                'status' => $owner->status,
            ],
        ]);
    }
}
