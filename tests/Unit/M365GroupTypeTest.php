<?php

namespace Tests\Unit;

use App\Enums\M365GroupType;
use Tests\TestCase;

class M365GroupTypeTest extends TestCase
{
    public function test_classifies_microsoft_365_group(): void
    {
        $type = M365GroupType::classify([
            'groupTypes' => ['Unified'],
            'mailEnabled' => true,
            'securityEnabled' => false,
        ]);

        $this->assertSame(M365GroupType::Microsoft365Group, $type);
    }

    public function test_classifies_distribution_list(): void
    {
        $type = M365GroupType::classify([
            'groupTypes' => [],
            'mailEnabled' => true,
            'securityEnabled' => false,
        ]);

        $this->assertSame(M365GroupType::DistributionList, $type);
    }

    public function test_classifies_security_group(): void
    {
        $type = M365GroupType::classify([
            'groupTypes' => [],
            'mailEnabled' => false,
            'securityEnabled' => true,
        ]);

        $this->assertSame(M365GroupType::SecurityGroup, $type);
    }
}
