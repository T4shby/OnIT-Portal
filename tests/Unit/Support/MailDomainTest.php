<?php

namespace Tests\Unit\Support;

use App\Support\MailDomain;
use Tests\TestCase;

class MailDomainTest extends TestCase
{
    public function test_normalises_common_input_shapes(): void
    {
        $this->assertSame('clientco.co.uk', MailDomain::normalize('ClientCo.co.uk'));
        $this->assertSame('clientco.co.uk', MailDomain::normalize('@clientco.co.uk.'));
        $this->assertSame('clientco.co.uk', MailDomain::normalize('https://clientco.co.uk/path'));
        $this->assertTrue(MailDomain::isValid('clientco.co.uk'));
        $this->assertFalse(MailDomain::isValid('not a domain'));
        $this->assertFalse(MailDomain::isValid('http://'));
    }
}
