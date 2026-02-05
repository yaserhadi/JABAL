<?php

namespace Tests\Unit\Modules;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Services\AuditLogger;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(AuditLoggerInterface::class, AuditLogger::class);
    }

    public function test_log_creates_audit_entry(): void
    {
        $logger = app(AuditLoggerInterface::class);

        $logger->log('test.event', [
            'auditable_type' => 'TestModel',
            'auditable_id' => 'test-id',
            'new_values' => ['foo' => 'bar'],
        ]);

        $this->assertDatabaseCount('audit_logs', 1);
        $log = AuditLog::first();
        $this->assertEquals('test.event', $log->event);
        $this->assertEquals('TestModel', $log->auditable_type);
    }
}
