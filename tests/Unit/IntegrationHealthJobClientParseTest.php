<?php

namespace Tests\Unit;

use App\Services\Admin\IntegrationHealthService;
use Tests\TestCase;

class IntegrationHealthJobClientParseTest extends TestCase
{
    public function test_extracts_client_id_from_laravel_database_queue_payload(): void
    {
        $service = app(IntegrationHealthService::class);

        $command = serialize(new \App\Jobs\RefreshDropsuiteBackupJob(10));
        $payload = json_encode([
            'displayName' => 'App\\Jobs\\RefreshDropsuiteBackupJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'App\\Jobs\\RefreshDropsuiteBackupJob',
                'command' => $command,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(10, $service->extractClientIdFromJobPayload($payload));

        // Escaped form as stored when Laravel puts serialize() inside a JSON string.
        $escaped = '{"displayName":"App\\\\Jobs\\\\RefreshDropsuiteBackupJob","data":{"command":"O:40:\\\\"App\\\\Jobs\\\\RefreshDropsuiteBackupJob\\\\":2:{s:8:\\\\"clientId\\\\";i:42;s:5:\\\\"queue\\\\";s:4:\\\\"high\\\\";}"}}';
        // Use stripcslashes-friendly payload actually seen in MySQL for command field
        $mysqlStyle = '{"uuid":"x","displayName":"App\\\\Jobs\\\\RefreshDropsuiteBackupJob","data":{"command":"O:40:\\"App\\\\Jobs\\\\RefreshDropsuiteBackupJob\\":2:{s:8:\\"clientId\\";i:42;s:5:\\"queue\\";s:4:\\"high\\";}"}}';
        $this->assertSame(42, $service->extractClientIdFromJobPayload($mysqlStyle));
    }
}
