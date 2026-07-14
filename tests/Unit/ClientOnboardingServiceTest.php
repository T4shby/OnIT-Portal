<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_step_section_has_a_where_path(): void
    {
        $client = Client::factory()->create([
            'name' => 'Path Check Ltd',
            'entra_license_tier' => 'free',
        ]);

        $steps = app(ClientOnboardingService::class)->steps($client);

        foreach ($steps as $step) {
            $this->assertNotEmpty($step['guide']['sections'], $step['key']);
            foreach ($step['guide']['sections'] as $section) {
                $this->assertNotEmpty($section['where'], $step['key'].':'.$section['title']);
                $this->assertNotEmpty($section['steps'], $step['key'].':'.$section['title']);
            }
        }
    }

    public function test_scim_provisioning_has_separate_where_for_mappings_and_app_roles(): void
    {
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_license_tier' => 'free',
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'superops_scim_provisioning');

        $titles = collect($step['guide']['sections'])->pluck('title')->all();
        $this->assertContains('Edit SCIM attribute mappings', $titles);
        $this->assertContains('Create App role Value User', $titles);
        $this->assertContains('Copy Application (client) ID into this portal (Entra Free)', $titles);
        $this->assertContains('Start provisioning in Azure', $titles);
        $this->assertStringContainsString('extensionAttribute1', implode(' ', $step['instructions']));
    }

    public function test_checklist_has_twelve_zero_training_steps(): void
    {
        $client = Client::factory()->create();

        $keys = collect(app(ClientOnboardingService::class)->steps($client))->pluck('key');

        $this->assertSame([
            'superops_linked',
            'pax8_linked',
            'entra_group_created',
            'entra_admin_consent_granted',
            'superops_scim_tokens',
            'superops_scim_app',
            'superops_scim_provisioning',
            'superops_client_sso_configured',
            'portal_sync_configured',
            'portal_sync_run',
            'login_tested',
            'handed_off',
        ], $keys->all());
        $this->assertFalse($keys->contains('platform_graph_permissions'));
        $this->assertSame(12, $keys->count());
    }

    public function test_run_sync_step_is_button_clicks_not_server_deploy(): void
    {
        $client = Client::factory()->create(['name' => 'SK Systems Limited']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'portal_sync_run')['instructions']);

        $this->assertStringContainsString('Dry run sync', $text);
        $this->assertStringContainsString('Sync now', $text);
        $this->assertStringContainsString('Mark this step complete', $text);
        $this->assertStringNotContainsString('git pull', $text);
        $this->assertStringNotContainsString('php artisan', $text);
        $this->assertStringNotContainsString('composer install', $text);
        $this->assertStringNotContainsString('cd /var/www', $text);
        $this->assertStringNotContainsString('ENTRA_SYNC_ENABLED', $text);
        $this->assertStringNotContainsString('Entity ID', $text);
        $this->assertStringNotContainsString('certificate', $text);
    }

    public function test_enable_sync_step_is_save_client_not_env(): void
    {
        $client = Client::factory()->create(['entra_license_tier' => 'free']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'portal_sync_configured')['instructions']);

        $this->assertStringContainsString('Entra sync enabled', $text);
        $this->assertStringContainsString('Save client', $text);
        $this->assertStringNotContainsString('ENTRA_SYNC_ENABLED', $text);
        $this->assertStringNotContainsString('php artisan', $text);
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
            'onboarding_checklist' => [
                'superops_scim_configured' => true,
                'superops_client_sso_configured' => true,
            ],
        ]);

        $progress = app(ClientOnboardingService::class)->progress($client);

        $this->assertGreaterThanOrEqual(8, $progress['complete']);
        $this->assertSame(12, $progress['total']);
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

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_group_created');

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

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_group_created');

        $this->assertFalse($step['complete']);
        $this->assertFalse($step['auto_detected']);
        $this->assertSame('Create Portal group + save Entra IDs', $step['title']);
    }

    public function test_group_id_auto_completes_security_group_step(): void
    {
        $client = Client::factory()->create([
            'entra_license_tier' => 'p1',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_group_created');

        $this->assertTrue($step['complete']);
        $this->assertTrue($step['auto_detected']);
    }

    public function test_legacy_scim_checkpoint_completes_all_three_scim_steps(): void
    {
        $client = Client::factory()->create([
            'onboarding_checklist' => ['superops_scim_configured' => true],
        ]);

        $steps = collect(app(ClientOnboardingService::class)->steps($client));

        $this->assertTrue($steps->firstWhere('key', 'superops_scim_tokens')['complete']);
        $this->assertTrue($steps->firstWhere('key', 'superops_scim_app')['complete']);
        $this->assertTrue($steps->firstWhere('key', 'superops_scim_provisioning')['complete']);
    }

    public function test_scim_provisioning_free_path_pastes_app_id(): void
    {
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_license_tier' => 'free',
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'superops_scim_provisioning');
        $text = implode(' ', $step['instructions']);

        $this->assertSame('Azure SCIM mappings + copy Application ID + start', $step['title']);
        $this->assertStringContainsString('Application (client) ID', $text);
        $this->assertStringContainsString('App registrations', $text);
        $this->assertStringContainsString('Object ID', $text);
        $this->assertStringContainsString('Save client', $text);
        $this->assertStringContainsString('Start provisioning', $text);
        $this->assertStringNotContainsString('Assign group', $text);
        $this->assertStringNotContainsString('Part C', $text);
        $this->assertStringNotContainsString('Munns', $text);
    }

    public function test_scim_provisioning_p1_path_assigns_group(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'entra_license_tier' => 'p1',
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'superops_scim_provisioning');
        $text = implode(' ', $step['instructions']);

        $this->assertSame('Azure SCIM mappings + assign group + start', $step['title']);
        $this->assertStringContainsString('On IT Portal - Acme Ltd', $text);
        $this->assertStringContainsString('Start provisioning', $text);
        $this->assertStringNotContainsString('SuperOps Application (client) ID', $text);
        $this->assertStringNotContainsString('Entra ID Free:', $text);
    }

    public function test_scim_app_step_includes_test_connection(): void
    {
        $client = Client::factory()->create(['name' => 'Ductec LTD']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'superops_scim_app')['instructions']);

        $this->assertStringContainsString('Test Connection', $text);
        $this->assertStringContainsString('SuperOps - Ductec LTD', $text);
        $this->assertStringContainsString('Bearer Authentication', $text);
        $this->assertStringContainsString('Create your own application', $text);
        $this->assertStringContainsString('Admin Credentials', $text);
        $this->assertStringContainsString('Non-gallery', $text);
        $this->assertStringNotContainsString('Entity ID', $text);
        $this->assertStringNotContainsString('Client SSO', $text);
    }

    public function test_sso_step_requires_customer_ga_accept(): void
    {
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        config(['services.superops.requester_sso_client_id' => 'bf1c303e-6015-43f7-abb2-5dfe8f67a5a1']);

        $service = app(ClientOnboardingService::class);
        $step = collect($service->steps($client))->firstWhere('key', 'superops_client_sso_configured');
        $text = implode(' ', $step['instructions']);
        $consentUrl = $service->superOpsRequesterSsoConsentUrl($client);

        $this->assertSame('Customer Accepts SuperOps login', $step['title']);
        $this->assertGreaterThanOrEqual(2, count($step['guide']['sections']));
        $this->assertStringContainsString('Open Microsoft Accept page', $text);
        $this->assertStringContainsString('MXVI Global Admin', $text);
        $this->assertStringContainsString('Users and groups', $text);
        $this->assertStringContainsString('portal.azure.com', $text);
        $this->assertStringContainsString('Accept', $text);
        $this->assertStringContainsString('Mark this step complete', $text);
        $this->assertStringContainsString('Client SSO', $text);
        $this->assertStringNotContainsString('Global SSO is broken', $text);
        $this->assertStringNotContainsString('Entity ID', $text);
        $this->assertStringNotContainsString('Consumer Service URL', $text);
        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/adminconsent',
            $consentUrl,
        );
        parse_str((string) parse_url((string) $consentUrl, PHP_URL_QUERY), $query);
        $this->assertSame('bf1c303e-6015-43f7-abb2-5dfe8f67a5a1', $query['client_id']);
    }

    public function test_superops_requester_sso_consent_url_requires_tenant(): void
    {
        config(['services.superops.requester_sso_client_id' => 'bf1c303e-6015-43f7-abb2-5dfe8f67a5a1']);

        $withoutTenant = Client::factory()->create(['entra_tenant_id' => null]);
        $this->assertNull(app(ClientOnboardingService::class)->superOpsRequesterSsoConsentUrl($withoutTenant));
    }

    public function test_group_step_is_click_path_only(): void
    {
        $client = Client::factory()->create([
            'name' => 'Ductec LTD',
            'entra_license_tier' => 'free',
        ]);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_group_created')['instructions']);

        $this->assertStringContainsString('New group', $text);
        $this->assertStringContainsString('On IT Portal - Ductec LTD', $text);
        $this->assertStringContainsString('Members', $text);
        $this->assertStringContainsString('Object ID', $text);
        $this->assertStringContainsString('Tenant ID', $text);
        $this->assertStringContainsString('Save client', $text);
        $this->assertStringContainsString('portal.azure.com', $text);
        $this->assertStringNotContainsString('9 Graph permissions', $text);
    }

    public function test_portal_graph_accept_step_is_button_first_copy(): void
    {
        $client = Client::factory()->create(['name' => '3R Systems Limited']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_admin_consent_granted')['instructions']);

        $this->assertStringContainsString('Open Microsoft Accept page', $text);
        $this->assertStringContainsString('Global Administrator of 3R Systems Limited', $text);
        $this->assertStringContainsString('OnIT Portal for Portals', $text);
        $this->assertStringContainsString('Enterprise applications', $text);
        $this->assertStringNotContainsString('User.Read.All', $text);
        $this->assertStringNotContainsString('Application.Read.All', $text);
        $this->assertStringNotContainsString('extensionAttribute1', $text);
    }

    public function test_successful_sync_auto_completes_admin_consent_step(): void
    {
        $client = Client::factory()->create([
            'entra_synced_at' => now(),
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_admin_consent_granted');

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
        $this->assertStringContainsString('On IT Portal - MXVI', implode(' ', $helps['entra_group_id']));
    }

    public function test_portal_sync_run_can_be_marked_complete_manually(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
            'entra_superops_app_id' => '33333333-3333-3333-3333-333333333333',
            'entra_sync_enabled' => true,
            'entra_synced_at' => null,
            'entra_license_tier' => 'free',
            'onboarding_checklist' => [
                'superops_scim_tokens' => true,
                'superops_scim_app' => true,
                'superops_scim_provisioning' => true,
            ],
        ]);

        config(['services.entra_sync.enabled' => true]);

        $service = app(ClientOnboardingService::class);
        $step = collect($service->steps($client))->firstWhere('key', 'portal_sync_run');

        $this->assertFalse($step['complete']);
        $this->assertTrue($step['manual']);
        $this->assertFalse($step['blocked']);

        $service->updateChecklist($client, ['portal_sync_run' => true]);
        $client->refresh();

        $step = collect($service->steps($client))->firstWhere('key', 'portal_sync_run');
        $this->assertTrue($step['complete']);
        $this->assertFalse(collect($service->steps($client))->firstWhere('key', 'login_tested')['blocked']);
    }

    public function test_updating_all_scim_substeps_sets_legacy_key(): void
    {
        $client = Client::factory()->create();
        $service = app(ClientOnboardingService::class);

        $service->updateChecklist($client, [
            'superops_scim_tokens' => true,
            'superops_scim_app' => true,
            'superops_scim_provisioning' => true,
        ]);
        $client->refresh();

        $this->assertTrue($client->onboarding_checklist['superops_scim_configured']);
    }
}
