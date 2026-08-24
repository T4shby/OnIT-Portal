<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\SuperOps\SuperOpsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ContactSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_admin_sees_contact_support_nav_and_hub(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => 'acc-1',
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $mock = Mockery::mock(SuperOpsTicketService::class);
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $this->app->instance(SuperOpsTicketService::class, $mock);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Contact Support')
            ->assertSee(route('contact-support.index'), false);

        $this->actingAs($admin)
            ->get(route('contact-support.index'))
            ->assertOk()
            ->assertSee('Log a ticket online')
            ->assertSee('03300 945 946')
            ->assertSee('service.desk@onit.ltd')
            ->assertSee('Unit G, Wheatley Park')
            ->assertSee('09:00-17:00')
            ->assertSee('New starter form');
    }

    public function test_technician_cannot_access_contact_support(): void
    {
        $tech = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::SuperAdmin,
        ]);

        $this->actingAs($tech)
            ->get(route('contact-support.index'))
            ->assertForbidden();

        $this->actingAs($tech)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Contact Support<', false);
    }

    public function test_client_requester_can_submit_new_starter_ticket(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'superops_account_id' => 'acc-99',
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'jane@acme.com',
            'name' => 'Jane Admin',
            'superops_user_id' => 'req-1',
        ]);

        $mock = Mockery::mock(SuperOpsTicketService::class);
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('createTicket')
            ->once()
            ->withArgs(function (User $actor, string $subject, string $description) use ($user): bool {
                return $actor->is($user)
                    && $subject === 'New starter request: Alex Joiner'
                    && str_contains($description, 'NEW STARTER REQUEST')
                    && str_contains($description, 'Alex Joiner')
                    && str_contains($description, 'Acme Ltd')
                    && str_contains($description, 'Laptop and M365');
            })
            ->andReturn([
                'ticketId' => 't-100',
                'displayId' => '100',
                'subject' => 'New starter request: Alex Joiner',
                'status' => 'Open',
            ]);
        $mock->shouldReceive('getTicket')->with('t-100')->andReturn([
            'ticketId' => 't-100',
            'displayId' => '100',
            'subject' => 'New starter request: Alex Joiner',
            'description' => 'NEW STARTER REQUEST',
            'status' => 'Open',
            'priority' => null,
            'createdTime' => now()->toIso8601String(),
            'updatedTime' => now()->toIso8601String(),
            'requester' => ['userId' => 'req-1', 'email' => 'jane@acme.com'],
        ]);
        $mock->shouldReceive('ticketBelongsToUser')->andReturn(true);
        $this->app->instance(SuperOpsTicketService::class, $mock);

        $this->actingAs($user)
            ->post(route('contact-support.new-starter.store'), [
                'starter_name' => 'Alex Joiner',
                'job_title' => 'Analyst',
                'start_date' => '2026-09-01',
                'department' => 'Ops',
                'manager_name' => 'Sam Boss',
                'starter_email' => 'alex@acme.com',
                'equipment_access' => 'Laptop and M365',
                'notes' => 'Needs VPN',
            ])
            ->assertRedirect(route('support.show', 't-100'));
    }
}
