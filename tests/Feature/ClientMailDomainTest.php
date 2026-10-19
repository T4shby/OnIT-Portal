<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\CheckClientDomainDnsJob;
use App\Models\Client;
use App\Models\ClientDomain;
use App\Models\User;
use App\Services\MailDns\MailDnsHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientMailDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_requires_main_domain_and_queues_dns_check(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->post(route('admin.clients.store'), [
                'name' => 'Acme Ltd',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('primary_domain');

        $this->actingAs($admin)
            ->post(route('admin.clients.store'), [
                'name' => 'Acme Ltd',
                'primary_domain' => 'https://Acme.co.uk/mail',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $client = Client::where('name', 'Acme Ltd')->firstOrFail();
        $this->assertSame('acme.co.uk', $client->primary_domain);
        $this->assertDatabaseHas('client_domains', [
            'client_id' => $client->id,
            'domain' => 'acme.co.uk',
            'is_primary' => 1,
        ]);

        Bus::assertDispatched(CheckClientDomainDnsJob::class);
    }

    public function test_missing_dns_records_are_red(): void
    {
        $empty = app(MailDnsHealthService::class)->checkDomain('example-invalid-dns-probe-'.uniqid().'.test');
        $this->assertSame('red', $empty['overall']);
        $this->assertSame('red', $empty['checks']['mx']['band']);
        $this->assertSame('red', $empty['checks']['spf']['band']);
        $this->assertSame('red', $empty['checks']['dkim']['band']);
        $this->assertSame('red', $empty['checks']['dmarc']['band']);
    }

    public function test_checklist_step_appears_on_edit(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'DNS Co',
            'primary_domain' => 'dnsco.example',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'dnsco.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => now(),
            'last_result' => [
                'overall' => 'yellow',
                'checks' => [
                    'mx' => ['band' => 'green', 'summary' => 'ok'],
                    'spf' => ['band' => 'green', 'summary' => 'ok'],
                    'dkim' => ['band' => 'yellow', 'summary' => 'weak'],
                    'dmarc' => ['band' => 'yellow', 'summary' => 'p=none'],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Confirm mail domain + DNS')
            ->assertSee('Mail domains')
            ->assertSee('dnsco.example');
    }

    public function test_create_intro_and_hand_off_warn_when_dns_still_red(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->get(route('admin.clients.create'))
            ->assertOk()
            ->assertSee('main domain')
            ->assertSee('both required', false);

        $client = Client::factory()->create([
            'name' => 'Red DNS Co',
            'primary_domain' => 'reddns.example',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'reddns.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => now(),
            'last_result' => [
                'overall' => 'red',
                'checks' => [
                    'mx' => ['band' => 'red', 'summary' => 'missing'],
                    'spf' => ['band' => 'red', 'summary' => 'missing'],
                    'dkim' => ['band' => 'red', 'summary' => 'missing'],
                    'dmarc' => ['band' => 'red', 'summary' => 'missing'],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Mail DNS still has')
            ->assertSee('hand off anyway', false);
    }
}

