<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientDomain;
use App\Models\User;
use App\Services\MailDns\MailDnsHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMailDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_requires_main_domain_and_runs_dns_check(): void
    {
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
        $row = ClientDomain::query()->where('client_id', $client->id)->where('domain', 'acme.co.uk')->firstOrFail();
        $this->assertTrue((bool) $row->is_primary);
        $this->assertNotNull($row->last_checked_at);
        $this->assertIsArray($row->last_result);
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

    public function test_edit_heals_primary_domain_without_dns_row(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => '3R Systems Limited',
            'primary_domain' => '3r.co.uk',
        ]);
        $this->assertSame(0, ClientDomain::query()->where('client_id', $client->id)->count());

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('3r.co.uk');

        $row = ClientDomain::query()->where('client_id', $client->id)->where('domain', '3r.co.uk')->firstOrFail();
        $this->assertTrue((bool) $row->is_primary);
        $this->assertNotNull($row->last_checked_at);
    }

    public function test_add_domain_works_as_standalone_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Multi Domain Co',
            'primary_domain' => 'primary.example',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'primary.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => now(),
            'last_result' => ['overall' => 'green', 'checks' => []],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.clients.domains.store', $client), [
                'domain' => 'mail.primary.example',
            ])
            ->assertRedirect(route('admin.clients.edit', $client));

        $row = ClientDomain::query()->where('client_id', $client->id)->where('domain', 'mail.primary.example')->firstOrFail();
        $this->assertFalse((bool) $row->is_primary);
        $this->assertNotNull($row->last_checked_at);
    }

    public function test_unchecked_domain_is_pending_not_red(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Pending DNS Co',
            'primary_domain' => 'pending.example',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'pending.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => null,
            'last_result' => null,
        ]);

        $row = ClientDomain::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertSame('pending', $row->overallBand());
        $this->assertFalse($row->hasRedChecks());

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('PENDING')
            ->assertDontSee('DNS still has red', false);
    }

    public function test_edit_page_separates_mail_domain_actions_from_save_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Form Layout Co',
            'primary_domain' => 'layout.example',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'layout.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => now(),
            'last_result' => ['overall' => 'green', 'checks' => []],
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Save client')
            ->assertSee('Import from Microsoft')
            ->assertSee('Add domain')
            ->assertSee('admin-split__main', false)
            ->assertSee('Export CSV')
            ->assertSee('Core = MX', false)
            ->getContent();

        // Nested forms break Add domain / Import in browsers - mail panel must sit outside Save.
        $this->assertMatchesRegularExpression(
            '/id="client-edit-form"[\s\S]*<\/form>[\s\S]*id="mail-domains"/',
            $html,
        );
    }
}
