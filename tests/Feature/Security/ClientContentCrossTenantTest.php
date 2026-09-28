<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientNotice;
use App\Models\ClientOpportunity;
use App\Models\ClientRecommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Notices / recommendations / opportunities / portal links take client_id from the
 * request. The policies only prove "is staff" (create) or "may touch the record's
 * current client" (update), so the submitted client must be checked too.
 */
class ClientContentCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Client $assigned;

    private Client $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assigned = Client::factory()->create();
        $this->other = Client::factory()->create();

        $this->manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $this->manager->assignedClients()->attach($this->assigned);
    }

    /**
     * @return array<string, array{0: string, 1: class-string, 2: array<string, mixed>}>
     */
    public static function contentProvider(): array
    {
        return [
            'notice' => ['notices', ClientNotice::class, ['title' => 'T', 'body' => 'B', 'is_active' => '1']],
            'recommendation' => ['recommendations', ClientRecommendation::class, ['title' => 'T', 'body' => 'B', 'category' => 'security', 'priority' => 'high', 'display_order' => 0, 'is_active' => '1']],
            'opportunity' => ['opportunities', ClientOpportunity::class, ['title' => 'T', 'body' => 'B', 'category' => 'backup', 'status' => 'open', 'display_order' => 0, 'is_active' => '1']],
        ];
    }

    #[DataProvider('contentProvider')]
    public function test_account_manager_cannot_create_content_for_unassigned_client(string $resource, string $model, array $payload): void
    {
        $this->actingAs($this->manager)
            ->post(route("admin.{$resource}.store"), [...$payload, 'client_id' => $this->other->id])
            ->assertSessionHasErrors('client_id');

        $this->assertSame(0, $model::query()->where('client_id', $this->other->id)->count());
    }

    #[DataProvider('contentProvider')]
    public function test_account_manager_can_create_content_for_assigned_client(string $resource, string $model, array $payload): void
    {
        $this->actingAs($this->manager)
            ->post(route("admin.{$resource}.store"), [...$payload, 'client_id' => $this->assigned->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route("admin.{$resource}.index"));

        $this->assertSame(1, $model::query()->where('client_id', $this->assigned->id)->count());
    }

    #[DataProvider('contentProvider')]
    public function test_account_manager_cannot_move_content_to_unassigned_client(string $resource, string $model, array $payload): void
    {
        $record = $model::create([...$payload, 'is_active' => true, 'client_id' => $this->assigned->id]);
        $param = str($resource)->singular()->toString();

        $this->actingAs($this->manager)
            ->put(route("admin.{$resource}.update", [$param => $record]), [...$payload, 'client_id' => $this->other->id])
            ->assertSessionHasErrors('client_id');

        $this->assertSame($this->assigned->id, $record->fresh()->client_id);
    }

    public function test_account_manager_cannot_create_portal_link_for_unassigned_client(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.portal-links.store'), [
                'name' => 'Sneaky',
                'link_type' => 'external',
                'url' => 'https://example.com',
                'client_id' => $this->other->id,
                'display_order' => 0,
            ])
            ->assertSessionHasErrors('client_id');

        $this->assertDatabaseMissing('portal_links', ['name' => 'Sneaky']);
    }

    public function test_account_manager_can_still_create_global_portal_link(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.portal-links.store'), [
                'name' => 'Global help',
                'link_type' => 'external',
                'url' => 'https://example.com/help',
                'client_id' => '',
                'display_order' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('portal_links', ['name' => 'Global help', 'client_id' => null]);
    }

    public function test_super_admin_can_create_content_for_any_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->post(route('admin.notices.store'), ['client_id' => $this->other->id, 'title' => 'T', 'body' => 'B', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientNotice::query()->where('client_id', $this->other->id)->count());
    }
}
