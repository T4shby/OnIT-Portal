<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\User;
use App\Services\Support\NewStarterTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewStarterTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_description_is_html_list_and_escapes_user_input(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Ltd']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'name' => 'Jane Admin',
            'email' => 'jane@acme.com',
        ]);

        $html = app(NewStarterTicketService::class)->buildDescription($user, [
            'starter_name' => 'Alex <script>alert(1)</script>',
            'job_title' => 'Analyst',
            'start_date' => '2026-09-01',
            'department' => 'Ops',
            'manager_name' => 'Sam Boss',
            'starter_email' => 'alex@acme.com',
            'equipment_access' => "Laptop\nand M365",
            'notes' => 'Needs VPN',
        ]);

        $this->assertStringContainsString('<p><strong>New starter request</strong>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li><strong>Requested by:</strong> Jane Admin (jane@acme.com)</li>', $html);
        $this->assertStringContainsString('Alex &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Laptop<br>'."\n".'and M365', $html);
        $this->assertStringContainsString('<strong>Additional notes</strong>', $html);
    }
}
