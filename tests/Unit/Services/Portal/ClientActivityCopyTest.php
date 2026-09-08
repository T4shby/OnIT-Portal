<?php

namespace Tests\Unit\Services\Portal;

use App\Services\Portal\ClientActivityCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientActivityCopyTest extends TestCase
{
    #[DataProvider('ticketProvider')]
    public function test_ticket_copy(string $status, string $title, string $badge): void
    {
        $mapped = (new ClientActivityCopy)->ticket($status);
        $this->assertSame($title, $mapped['title']);
        $this->assertSame($badge, $mapped['badge']);
    }

    public static function ticketProvider(): array
    {
        return [
            'waiting on client' => [
                'Waiting on Client',
                'We sent an update and are waiting on you',
                'Waiting on you',
            ],
            'in progress' => [
                'In Progress',
                'A technician is working on this',
                'In progress',
            ],
            'on hold' => [
                'On Hold',
                'We have paused this ticket',
                'On hold',
            ],
            'open' => [
                'Open',
                'This is in our support queue',
                'Open',
            ],
            'closed' => [
                'Closed',
                'We closed this ticket',
                'Closed',
            ],
            'vendor' => [
                'Waiting on Vendor',
                'We are waiting on a supplier',
                'Waiting on supplier',
            ],
            'reopened' => [
                'Reopened',
                'We have reopened this ticket',
                'Reopened',
            ],
        ];
    }

    public function test_security_and_backup_copy(): void
    {
        $copy = new ClientActivityCopy;

        $open = $copy->securityCase(true);
        $this->assertSame('We are investigating a security case', $open['title']);
        $this->assertSame('Open', $open['badge']);

        $closed = $copy->securityCase(false);
        $this->assertSame('We closed a security case', $closed['title']);

        $approve = $copy->threatResponse('Rotate the credentials', 'unapproved');
        $this->assertSame('Please approve rotating credentials', $approve['title']);
        $this->assertSame('Needs your approval', $approve['badge']);

        $done = $copy->threatResponse('isolate_host', 'completed');
        $this->assertSame('We isolated a device', $done['title']);
        $this->assertSame('Completed', $done['badge']);

        $backup = $copy->backup('failed');
        $this->assertSame('We are checking a failed mailbox backup', $backup['title']);
        $this->assertSame('Failed', $backup['badge']);
    }
}
