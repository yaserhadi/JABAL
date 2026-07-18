<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Classifies the request Host. Classification is NOT Tenant resolution (BK-073).
 *
 * Labels: platform | auth | api | asset | operations | tenant_candidate | unknown
 * Unknown fails closed. Reserved classes never enter Stancl.
 */
class RequestHostClassifier
{
    public const ATTRIBUTE = 'host_class';

    public const CLASS_PLATFORM = 'platform';

    public const CLASS_AUTH = 'auth';

    public const CLASS_API = 'api';

    public const CLASS_ASSET = 'asset';

    public const CLASS_OPERATIONS = 'operations';

    public const CLASS_TENANT_CANDIDATE = 'tenant_candidate';

    public const CLASS_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const RESERVED_CLASSES = [
        self::CLASS_PLATFORM,
        self::CLASS_AUTH,
        self::CLASS_API,
        self::CLASS_ASSET,
        self::CLASS_OPERATIONS,
    ];

    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $class = $this->classify($request);
        $request->attributes->set(self::ATTRIBUTE, $class);

        if ($class === self::CLASS_UNKNOWN) {
            abort(404, 'Unknown host.');
        }

        return $next($request);
    }

    public function classify(Request $request): string
    {
        $host = strtolower($request->getHost());

        if ($host === '') {
            return self::CLASS_UNKNOWN;
        }

        $map = [
            self::CLASS_PLATFORM => $this->addressing->platformHost(),
            self::CLASS_AUTH => $this->addressing->authHost(),
            self::CLASS_API => $this->addressing->apiHost(),
            self::CLASS_ASSET => $this->addressing->assetHost(),
            self::CLASS_OPERATIONS => $this->addressing->operationsHost(),
        ];

        foreach ($map as $class => $configured) {
            if ($configured !== '' && $host === strtolower($configured)) {
                return $class;
            }
        }

        // Platform base domain itself (apex) is reserved / platform surface.
        $base = $this->addressing->platformBaseDomain();
        if ($base !== '' && $host === strtolower($base)) {
            return self::CLASS_PLATFORM;
        }

        // Explicit central_hosts list (e.g. localhost, 127.0.0.1) → platform.
        if (in_array($host, $this->addressing->centralHosts(), true)) {
            return self::CLASS_PLATFORM;
        }

        if ($this->addressing->isHost() && $base !== '') {
            $suffix = '.'.strtolower($base);
            if (str_ends_with($host, $suffix)) {
                $label = substr($host, 0, -strlen($suffix));
                // Single-label only — nested subdomains are unknown (fail closed).
                if ($label !== '' && ! str_contains($label, '.') && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                    return self::CLASS_TENANT_CANDIDATE;
                }

                return self::CLASS_UNKNOWN;
            }

            // Host profile: anything not reserved and not under base is unknown.
            return self::CLASS_UNKNOWN;
        }

        // Path profile: non-reserved hosts are treated as platform (single-origin path serving).
        return self::CLASS_PLATFORM;
    }

    public static function classOf(Request $request): ?string
    {
        $value = $request->attributes->get(self::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }

    public static function isReserved(?string $class): bool
    {
        return $class !== null && in_array($class, self::RESERVED_CLASSES, true);
    }
}
