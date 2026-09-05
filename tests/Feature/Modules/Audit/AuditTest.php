<?php

namespace Tests\Feature\Modules\Audit;

use Modules\Identity\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Audit\Models\AuditLog;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_is_created_on_model_creation(): void
    {
        $user = TenantUser::factory()->create();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.created',
            'auditable_type' => TenantUser::class,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_audit_log_is_created_on_model_update(): void
    {
        $user = TenantUser::factory()->create();

        $user->update(['name' => 'Updated Name']);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.updated',
            'auditable_type' => TenantUser::class,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_audit_log_is_created_on_model_deletion(): void
    {
        $user = TenantUser::factory()->create();
        $userId = $user->id;

        $user->delete();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.deleted',
            'auditable_type' => TenantUser::class,
            'auditable_id' => $userId,
        ]);
    }

    public function test_audit_log_captures_old_and_new_values(): void
    {
        $user = TenantUser::factory()->create(['name' => 'Old Name']);

        $user->update(['name' => 'New Name']);

        $auditLog = AuditLog::where('event', 'user.updated')
            ->where('auditable_id', $user->id)
            ->latest()
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('Old Name', $auditLog->old_values['name']);
        $this->assertEquals('New Name', $auditLog->new_values['name']);
    }

    public function test_can_retrieve_audit_logs_for_model(): void
    {
        $user = TenantUser::factory()->create();
        $user->update(['name' => 'Updated']);

        $logs = AuditLog::where('auditable_type', TenantUser::class)
            ->where('auditable_id', $user->id)
            ->orderBy('created_at')
            ->get();

        $this->assertGreaterThanOrEqual(2, $logs->count()); // At least created and updated
    }
}
