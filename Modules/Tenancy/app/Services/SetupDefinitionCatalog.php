<?php

namespace Modules\Tenancy\Services;

use Modules\Tenancy\Models\SetupDefinition;

/**
 * WAVE-6: Idempotent setup definition catalog (not a workflow engine).
 */
class SetupDefinitionCatalog
{
    public function ensureDefaults(): void
    {
        $defs = [
            [
                'code' => 'company_profile',
                'title' => 'Company profile',
                'requirement_type' => SetupDefinition::TYPE_BLOCKING,
                'capability_code' => null,
                'condition_entitlement_code' => null,
            ],
            [
                'code' => 'business_owner_confirmation',
                'title' => 'Business Owner confirmation',
                'requirement_type' => SetupDefinition::TYPE_OPTIONAL,
                'capability_code' => null,
                'condition_entitlement_code' => null,
            ],
            [
                'code' => 'sso_configuration',
                'title' => 'SSO configuration',
                'requirement_type' => SetupDefinition::TYPE_CONDITIONAL,
                'capability_code' => 'sso',
                'condition_entitlement_code' => 'sso_available',
            ],
        ];

        foreach ($defs as $def) {
            SetupDefinition::query()->firstOrCreate(
                ['code' => $def['code'], 'version' => 1],
                [
                    'title' => $def['title'],
                    'requirement_type' => $def['requirement_type'],
                    'capability_code' => $def['capability_code'],
                    'condition_entitlement_code' => $def['condition_entitlement_code'],
                    'is_active' => true,
                ]
            );
        }
    }
}
