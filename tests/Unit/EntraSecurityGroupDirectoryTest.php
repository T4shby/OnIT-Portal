<?php

namespace Tests\Unit;

use App\Services\EntraSync\EntraSecurityGroupDirectory;
use App\Services\EntraSync\MicrosoftGraphClient;
use Tests\TestCase;

class EntraSecurityGroupDirectoryTest extends TestCase
{
    public function test_selectable_keeps_static_security_groups_only(): void
    {
        $directory = new EntraSecurityGroupDirectory($this->createMock(MicrosoftGraphClient::class));

        $options = $directory->selectable([
            ['id' => '11111111-1111-1111-1111-111111111111', 'displayName' => 'Finance', 'securityEnabled' => true, 'groupTypes' => []],
            ['id' => '22222222-2222-2222-2222-222222222222', 'displayName' => 'All staff', 'securityEnabled' => true, 'groupTypes' => ['Unified']],
            ['id' => '33333333-3333-3333-3333-333333333333', 'displayName' => 'Auto laptops', 'securityEnabled' => true, 'groupTypes' => ['DynamicMembership']],
            ['id' => '44444444-4444-4444-4444-444444444444', 'displayName' => 'Newsletter', 'securityEnabled' => false, 'groupTypes' => []],
            ['id' => '55555555-5555-5555-5555-555555555555', 'displayName' => 'VPN users', 'securityEnabled' => true, 'groupTypes' => []],
        ]);

        $this->assertSame(['Finance', 'VPN users'], array_column($options, 'name'));
    }
}
