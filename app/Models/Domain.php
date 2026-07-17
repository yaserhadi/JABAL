<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Models\Domain as StanclDomain;

/**
 * Application Domain model — adds JSON `data` cast for BK-073 metadata contract.
 *
 * Stock Stancl Domain has no `data` column/cast. Configured via tenancy.domain_model.
 *
 * @property array<string, mixed>|null $data
 */
class Domain extends StanclDomain
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
