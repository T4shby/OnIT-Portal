<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_checklist_does_not_include_platform_graph_step(): void
    {
        $client = Client::factory()->create();

        $keys = collect(app(ClientOnboardingService::class)->steps($client))->pluck('key');

        $this->assertFalse($keys->contains('platform_graph_permissions'));
        $this->assertFalse($keys->contains('portal_client_created'));
        $this->assertSame(10, $keys->count());
    }

    public function test_run_sync_step_includes_server_deploy_commands(): void
    {
        $client = Client::factory()->create(['id' => 42]);

        $instructions = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'portal_sync_run')['instructions'];

        $text = implode(' ', $instructions);

        $this->assertStringContainsString('git pull origin main', $text);
        $this->assertStringContainsString('--client=42', $text);
        $this->assertStringContainsString('ENTRA_SYNC_ENABLED=true', $text);
    }

    public function test_admin_consent_url_uses_tenant_and_app_client_id(): void
    {
        config(['services.entra_sync.client_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $url = app(ClientOnboardingService::class)->adminConsentUrl($client);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/adminconsent',
            $url,
        );
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $query['client_id']);
        $this->assertSame(config('services.azure.redirect'), $query['redirect_uri']);
        $this->assertSame(\App\Support\AdminConsentState::encode($client->id), $query['state']);
    }

    public function test_progress_counts_auto_completed_steps(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => '12345',
            'pax8_sso_enabled' => false,
            'entra_license_tier' => 'p1',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
            'entra_sync_enabled' => true,
            'entra_synced_at' => now(),
        ]);

        $progress = app(ClientOnboardingService::class)->progress($client);

        $this->assertGreaterThanOrEqual(6, $progress['complete']);
        $this->assertSame(10, $progress['total']);
    }

    public function test_unsaved_client_does_not_mark_superops_linked_complete(): void
    {
        $client = new Client(['name' => 'Draft Co']);

        $steps = collect(app(ClientOnboardingService::class)->steps($client));

        $this->assertFalse($steps->firstWhere('key', 'superops_linked')['complete']);
    }

    public function test_pax8_step_complete_when_disabled_or_company_id_set(): void
    {
        $service = app(ClientOnboardingService::class);

        $withoutPax8 = Client::factory()->create(['pax8_sso_enabled' => false, 'pax8_company_id' => null]);
        $withPax8 = Client::factory()->create(['pax8_sso_enabled' => true, 'pax8_company_id' => 'abc-123']);

        $stepsWithout = collect($service->steps($withoutPax8));
        $stepsWith = collect($service->steps($withPax8));

        $this->assertTrue($stepsWithout->firstWhere('key', 'pax8_linked')['complete']);
        $this->assertTrue($stepsWith->firstWhere('key', 'pax8_linked')['complete']);
    }

    public function test_tenant_id_alone_does_not_complete_security_group_step_on_p1(): void
    {
        $client = Client::factory()->create([
            'entra_license_tier' => 'p1',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => null,
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_group_created');

        $this->assertFalse($step['complete']);
        $this->assertFalse($step['auto_detected']);
    }

    public function test_tenant_id_alone_does_not_complete_security_group_step_on_free(): void
    {
        $client = Client::factory()->create([
            'entra_license_tier' => 'free',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => null,
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_group_created');

        $this->assertFalse($step['complete']);
        $this->assertFalse($step['auto_detected']);
        $this->assertSame('M365 security group + Entra tenant', $step['title']);
    }

    public function test_group_id_auto_completes_security_group_step(): void
    {
        $client = Client::factory()->create([
            'entra_license_tier' => 'p1',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_group_created');

        $this->assertTrue($step['complete']);
        $this->assertTrue($step['auto_detected']);
    }

    public function test_scim_part_c_maps_attributes_without_pilot_name_examples(): void
    {
        $client = Client::factory()->create(['name' => 'MXVI']);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_configured');

        $partC = collect($step['guide']['sections'])
            ->first(fn (array $section) => str_contains($section['title'], 'Part C'));

        $this->assertCount(5, $partC['steps']);
        $this->assertCount(2, $partC['notes']);
        $this->assertStringContainsString('Surname (User Mailbox)', implode(' ', $partC['notes']));
        $this->assertStringNotContainsString('No Expression needed', implode(' ', $partC['steps']));
        $this->assertStringNotContainsString('Munns', implode(' ', array_merge($partC['steps'], $partC['notes'])));
    }

    public function test_scim_step_includes_detailed_instructions(): void
    {
        $client = Client::factory()->create(['name' => 'Ductec LTD']);

        $instructions = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_configured')['instructions'];

        $text = implode(' ', $instructions);

        $this->assertGreaterThanOrEqual(10, count($instructions));
        $this->assertStringContainsString('Test Connection', $text);
        $this->assertStringContainsString('Ductec LTD', $text);
        $this->assertStringContainsString('Manage', $text);
        $this->assertStringContainsString('Admin Credentials', $text);
        $this->assertStringContainsString('Connect your application', $text);
    }

    public function test_sso_step_uses_manage_single_sign_on_path(): void
    {
        $client = Client::factory()->create(['name' => 'MXVI']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_client_sso_configured')['instructions']);

        $this->assertStringContainsString('Manage', $text);
        $this->assertStringContainsString('Single sign-on', $text);
    }

    public function test_group_step_clarifies_portal_vs_superops_scope_on_free(): void
    {
        $client = Client::factory()->create([
            'name' => 'Ductec LTD',
            'entra_license_tier' => 'free',
        ]);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_group_created')['instructions']);

        $this->assertStringContainsString('New group', $text);
        $this->assertStringContainsString('On IT Portal - Ductec LTD', $text);
        $this->assertStringContainsString('Why this group on Entra ID Free?', $text);
        $this->assertStringContainsString('Do not assign this group to the SuperOps app', $text);
        $this->assertStringContainsString('Sync now', $text);
        $this->assertStringNotContainsString('Do not create a security group', $text);
    }

    public function test_free_part_d_explains_portal_field_and_overview_app_id(): void
    {
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_license_tier' => 'free',
        ]);

        $partD = collect(
            collect(app(ClientOnboardingService::class)->steps($client))
                ->firstWhere('key', 'superops_scim_configured')['guide']['sections']
        )->first(fn (array $section) => str_contains($section['title'], 'Part D'));

        $text = implode(' ', array_merge($partD['steps'], $partD['notes'] ?? []));

        $this->assertStringContainsString('On Entra ID Free you do not assign', $text);
        $this->assertStringNotContainsString('Entra ID P1', $text);
        $this->assertStringNotContainsString('Users and groups → Add user/group', $text);
        $this->assertCount(3, $partD['steps']);
    }

    public function test_free_scim_guide_does_not_show_p1_and_free_paths_together(): void
    {
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_license_tier' => 'free',
        ]);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_configured')['instructions']);

        $this->assertStringContainsString('Entra ID Free', $text);
        $this->assertStringNotContainsString('Entra ID P1:', $text);
        $this->assertStringContainsString('SuperOps Application (client) ID', $text);
    }

    public function test_p1_scim_guide_uses_group_assignment_not_app_id_path(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'entra_license_tier' => 'p1',
        ]);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_configured')['instructions']);

        $this->assertStringContainsString('On IT Portal - Acme Ltd', $text);
        $this->assertStringNotContainsString('Entra ID Free:', $text);
    }

    public function test_successful_sync_auto_completes_admin_consent_step(): void
    {
        $client = Client::factory()->create([
            'entra_synced_at' => now(),
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_admin_consent_granted');

        $this->assertTrue($step['complete']);
        $this->assertTrue($step['auto_detected']);
    }

    public function test_checklist_uses_msp_role_labels_not_you(): void
    {
        $client = Client::factory()->create(['name' => 'Ductec LTD']);

        $steps = app(ClientOnboardingService::class)->steps($client);

        foreach ($steps as $step) {
            $this->assertNotSame('You', $step['who']);
            $this->assertNotSame('M365 admin', $step['who']);
            $this->assertStringContainsString('On IT technician', $step['who']);
        }
    }

    public function test_field_helps_include_entra_ids(): void
    {
        $client = Client::factory()->create(['name' => 'MXVI', 'entra_license_tier' => 'free']);
        $helps = app(ClientOnboardingService::class)->fieldHelps($client);

        $this->assertArrayHasKey('entra_tenant_id', $helps);
        $this->assertArrayHasKey('entra_license_tier', $helps);
        $this->assertArrayHasKey('entra_group_id', $helps);
        $this->assertArrayHasKey('entra_superops_app_id', $helps);
        $this->assertStringContainsString('MXVI', implode(' ', $helps['entra_superops_app_id']));
        $this->assertStringContainsString('Create this group in step 03', implode(' ', $helps['entra_group_id']));
        $this->assertStringContainsString('upgrade to P1', implode(' ', $helps['entra_group_id']));
    }

    public function test_update_checklist_persists_manual_checkpoints(): void
    {
        $client = Client::factory()->create();
        $service = app(ClientOnboardingService::class);

        $service->updateChecklist($client, [
            'entra_group_created' => true,
            'login_tested' => true,
        ]);

        $client->refresh();

        $this->assertTrue($client->onboarding_checklist['entra_group_created']);
        $this->assertTrue($client->onboarding_checklist['login_tested']);
        $this->assertFalse($client->onboarding_checklist['handed_off'] ?? false);
    }
}
