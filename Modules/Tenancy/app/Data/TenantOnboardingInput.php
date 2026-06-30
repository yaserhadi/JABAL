<?php

namespace Modules\Tenancy\Data;

final class TenantOnboardingInput
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $ownerName,
        public readonly string $ownerEmail,
        public readonly string $ownerPassword,
        public readonly string $isolationLevel = 'shared',
        public readonly ?string $slug = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            organizationName: (string) $data['organization_name'],
            ownerName: (string) $data['owner_name'],
            ownerEmail: (string) $data['owner_email'],
            ownerPassword: (string) $data['owner_password'],
            isolationLevel: (string) ($data['isolation_level'] ?? 'shared'),
            slug: isset($data['slug']) ? (string) $data['slug'] : null,
        );
    }
}
