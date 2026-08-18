<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Support\AdminConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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
            $this->assertIsArray($step['guide']['sections'], $step['key']);
            $this->assertIsArray($step['guide']['automated'] ?? [], $step['key'].' automated');
            $this->assertNotEmpty($step['guide']['recovery'] ?? [], $step['key'].' recovery');
            foreach ($step['guide']['sections'] as $section) {
                $this->assertNotEmpty($section['where'], $step['key'].':'.$section['title']);
                $this->assertNotEmpty($section['steps'], $step['key'].':'.$section['title']);
            }
            foreach ($step['guide']['recovery'] ?? [] as $section) {
                $this->assertNotEmpty($section['where'], $step['key'].':recovery:'.$section['title']);
                $this->assertNotEmpty($section['steps'], $step['key'].':recovery:'.$section['title']);
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

        $sectionTitles = collect($step['guide']['sections'])->pluck('title')->all();
        $recoveryTitles = collect($step['guide']['recovery'] ?? [])->pluck('title')->all();
        $this->assertContains('Remaining: Apply SuperOps SCIM tokens', $sectionTitles);
        $this->assertContains('Apply button failed - paste credentials in Azure', $recoveryTitles);
        $this->assertContains('Name mappings not auto-applied', $recoveryTitles);
        $this->assertContains('App role or group assign missing', $recoveryTitles);
        $this->assertNotEmpty($step['guide']['automated'] ?? []);
        $this->assertStringContainsString('extensionAttribute1', implode(' ', $step['instructions']));
        $this->assertStringNotContainsString('Ductec', implode(' ', $step['instructions']));
        $this->assertStringNotContainsString('3R Systems', implode(' ', $step['instructions']));
    }

    public function test_scim_step_07_not_complete_without_mappings_and_sync_queue(): void
    {
        $client = Client::factory()->create([
            'name' => 'YorPower',
            'entra_license_tier' => 'p1',
            'onboarding_checklist' => [
                'superops_scim_tokens' => true,
                'superops_scim_app' => true,
                'superops_scim_provisioning' => true,
                'superops_scim_name_mappings' => false,
                'superops_scim_sync_queued' => false,
            ],
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_provisioning');

        $this->assertFalse($step['complete']);
    }

    public function test_scim_step_shows_failed_when_live_export_unhealthy(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_superops_app_id' => '33333333-3333-3333-3333-333333333333',
            'onboarding_checklist' => [
                'superops_scim_name_mappings' => true,
                'superops_scim_sync_queued' => true,
                'superops_scim_configured' => true,
            ],
        ]);

        $graph = Mockery::mock(\App\Services\EntraSync\MicrosoftGraphClient::class);
        $graph->shouldReceive('getSuperOpsScimProvisioningHealth')->andReturn([
            'ok' => false,
            'needsApplyScim' => false,
            'needsRepair' => true,
            'error' => 'Entra SCIM export is not active.',
        ]);
        $this->app->instance(\App\Services\EntraSync\MicrosoftGraphClient::class, $graph);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_provisioning');

        $this->assertFalse($step['complete']);
        $this->assertTrue($step['failed']);
        $this->assertStringContainsString('not active', $step['failure_summary']);
    }

    public function test_scim_step_complete_when_live_export_healthy(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_superops_app_id' => '33333333-3333-3333-3333-333333333333',
            'onboarding_checklist' => [
                'superops_scim_name_mappings' => true,
                'superops_scim_sync_queued' => true,
            ],
        ]);

        $graph = Mockery::mock(\App\Services\EntraSync\MicrosoftGraphClient::class);
        $graph->shouldReceive('getSuperOpsScimProvisioningHealth')->andReturn([
            'ok' => true,
            'needsApplyScim' => false,
        ]);
        $this->app->instance(\App\Services\EntraSync\MicrosoftGraphClient::class, $graph);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_provisioning');

        $this->assertTrue($step['complete']);
        $this->assertFalse($step['failed'] ?? false);
    }

    public function test_scim_step_07_complete_when_mappings_and_sync_recorded(): void
    {
        $client = Client::factory()->create([
            'onboarding_checklist' => [
                'superops_scim_name_mappings' => true,
                'superops_scim_sync_queued' => true,
            ],
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_scim_provisioning');

        $this->assertTrue($step['complete']);
        $this->assertTrue($step['auto_detected']);
        $this->assertFalse($step['manual']);
    }

    public function test_scope_warning_when_superops_bulk_exceeds_portal_entra_users(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => 'acc-1',
            'entra_synced_at' => now(),
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'entra_object_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $syncService = Mockery::mock(\App\Services\SuperOps\SuperOpsUserSyncService::class);
        $syncService->shouldReceive('countClientRequesters')->andReturn(123);
        $this->app->instance(\App\Services\SuperOps\SuperOpsUserSyncService::class, $syncService);

        $warnings = app(ClientOnboardingService::class)->superOpsEntraScopeWarnings($client);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('123', $warnings[0]);
        $this->assertStringContainsString('1', $warnings[0]);
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

    public function test_admin_consent_url_uses_organizations_when_tenant_unknown(): void
    {
        config(['services.entra_sync.client_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $client = Client::factory()->create([
            'entra_tenant_id' => null,
        ]);

        $url = app(ClientOnboardingService::class)->adminConsentUrl($client);

        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/organizations/adminconsent',
            $url,
        );
        $this->assertStringContainsString(AdminConsentState::encode($client->id), $url);
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
        $this->assertSame('Connect Microsoft tenant (IDs + group + licence)', $step['title']);
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

        $this->assertSame('Apply SCIM tokens + start (Free)', $step['title']);
        $this->assertStringContainsString('Application (client) ID', $text);
        $this->assertStringContainsString('App registrations', $text);
        $this->assertStringContainsString('SCIM Application (client) ID', $text);
        $this->assertStringContainsString('Start provisioning', $text);
        $this->assertStringContainsString('Test Connection', $text);
        $this->assertStringNotContainsString('Part C', $text);
        $this->assertStringNotContainsString('Munns', $text);
        $this->assertStringNotContainsString('Ductec', $text);
        $this->assertStringNotContainsString('3R Systems', $text);
        $this->assertStringContainsString('background', $text);
        $this->assertStringContainsString('few minutes', $text);
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

        $this->assertSame('Apply SCIM tokens + start provisioning', $step['title']);
        $this->assertStringContainsString('On IT Portal - Acme Ltd', $text);
        $this->assertStringContainsString('Start provisioning', $text);
        $this->assertStringContainsString('Test Connection', $text);
        $this->assertStringContainsString('background', $text);
        $this->assertStringContainsString('few minutes', $text);
        $this->assertStringNotContainsString('Ductec', $text);
        $this->assertStringNotContainsString('3R Systems', $text);
    }

    public function test_scim_app_step_is_auto_after_connect(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Ltd']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'superops_scim_app')['instructions']);

        $this->assertStringContainsString('Connect Microsoft', $text);
        $this->assertStringContainsString('SuperOps - Acme Ltd', $text);
        $this->assertStringContainsString('SuperOps Application (client) ID', $text);
        $this->assertStringContainsString('Application.ReadWrite.All', $text);
        $this->assertStringNotContainsString('Entity ID', $text);
        $this->assertStringNotContainsString('Client SSO', $text);
    }

    public function test_sso_step_uses_customer_owned_client_sso(): void
    {
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $step = collect(app(ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'superops_client_sso_configured');
        $text = implode(' ', $step['instructions']);

        $this->assertSame('SuperOps Microsoft login (Client SSO)', $step['title']);
        $this->assertNotEmpty($step['guide']['automated']);
        $this->assertNotEmpty($step['guide']['recovery']);
        $this->assertLessThanOrEqual(2, count($step['guide']['sections']));
        $this->assertStringContainsString('Already automatic', $text);
        $this->assertStringContainsString('Client SSO', $text);
        $this->assertStringContainsString('SuperOps Requester SSO - MXVI', $text);
        $this->assertStringContainsString('Entity ID', $text);
        $this->assertStringContainsString('Consumer service URL', $text);
        $this->assertStringContainsString('Wire SuperOps into Microsoft Entra', $text);
        $this->assertStringContainsString('If it fails', $text);
        $this->assertStringContainsString('portal.azure.com', $text);
        $this->assertStringContainsString('do not add users by hand', $text);
        $this->assertStringContainsString('Sync now', $text);
        $this->assertStringNotContainsString('add each customer requester', $text);
        $this->assertStringNotContainsString('adminconsent', $text);
        $this->assertStringNotContainsString('AADSTS1003031', $text);
        $this->assertStringNotContainsString('Ductec', $text);
        $this->assertStringNotContainsString('3R Systems', $text);
    }

    public function test_sso_step_does_not_render_retired_global_accept_button(): void
    {
        $client = Client::factory()->create([
            'name' => 'Northwind Ltd',
            'onboarding_checklist' => ['superops_client_sso_configured' => true],
        ]);

        $html = view('admin.clients._onboarding-steps', [
            'client' => $client,
            'onboardingSteps' => app(ClientOnboardingService::class)->steps($client),
            'adminConsentUrl' => null,
            'showCheckboxes' => true,
        ])->render();

        $this->assertStringContainsString('SuperOps Microsoft login (Client SSO)', $html);
        $this->assertStringNotContainsString('Open SuperOps SSO Accept', $html);
        $this->assertStringNotContainsString('adminconsent', $html);
    }

    public function test_group_step_is_click_path_only(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'entra_license_tier' => 'free',
        ]);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_group_created')['instructions']);

        $this->assertStringContainsString('Connect Microsoft tenant', $text);
        $this->assertStringContainsString('automatically', $text);
        $this->assertStringContainsString('GDAP', $text);
        $this->assertStringContainsString('On IT Portal - Acme Ltd', $text);
        $this->assertStringNotContainsString('switch directory', $text);
        $this->assertStringNotContainsString('9 Graph permissions', $text);
    }

    public function test_portal_graph_accept_step_is_button_first_copy(): void
    {
        $client = Client::factory()->create(['name' => 'Northwind Ltd']);

        $text = implode(' ', collect(app(ClientOnboardingService::class)->steps($client)
            )->firstWhere('key', 'entra_admin_consent_granted')['instructions']);

        $this->assertStringContainsString('Connect Microsoft tenant', $text);
        $this->assertStringContainsString('Accept', $text);
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
        $client = Client::factory()->create(['name' => 'Acme Ltd']);

        $steps = app(ClientOnboardingService::class)->steps($client);

        foreach ($steps as $step) {
            $this->assertStringContainsString('On IT', $step['who']);
            $this->assertStringNotContainsString('You', $step['who']);
            $this->assertNotSame('M365 admin', $step['who']);
        }
    }

    public function test_customer_is_never_assigned_onboarding_actions(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Ltd']);
        $steps = collect(app(ClientOnboardingService::class)->steps($client));
        $allText = $steps
            ->flatMap(fn (array $step): array => [$step['title'], ...$step['instructions']])
            ->implode(' ');

        $this->assertSame(
            'Accept Portal Graph (starts auto setup)',
            $steps->firstWhere('key', 'entra_admin_consent_granted')['title'],
        );
        $this->assertSame(
            'SuperOps Microsoft login (Client SSO)',
            $steps->firstWhere('key', 'superops_client_sso_configured')['title'],
        );
        $this->assertStringContainsString('On IT **GDAP**', $allText);
        $this->assertStringContainsString('private/incognito', $allText);
        $this->assertStringNotContainsString('Customer Accepts', $allText);
        $this->assertStringNotContainsString('customer Global Admin', $allText);
        $this->assertStringNotContainsString('send it to them', $allText);
        $this->assertStringNotContainsString('MSP-owned setup', $allText);
    }

    public function test_field_helps_include_entra_ids(): void
    {
        $client = Client::factory()->create(['name' => 'MXVI', 'entra_license_tier' => 'free']);
        $helps = app(ClientOnboardingService::class)->fieldHelps($client);

        $this->assertArrayHasKey('entra_tenant_id', $helps);
        $this->assertArrayHasKey('entra_license_tier', $helps);
        $this->assertArrayHasKey('entra_group_id', $helps);
        $this->assertArrayHasKey('entra_superops_app_id', $helps);
        $this->assertArrayHasKey('entra_superops_sso_app_id', $helps);
        $this->assertStringContainsString('MXVI', implode(' ', $helps['entra_superops_app_id']));
        $this->assertStringContainsString('MXVI', implode(' ', $helps['entra_superops_sso_app_id']));
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
            'superops_scim_name_mappings' => true,
            'superops_scim_sync_queued' => true,
            'superops_scim_provisioning' => true,
        ]);
        $client->refresh();

        $this->assertTrue($client->onboarding_checklist['superops_scim_configured']);
        $this->assertTrue($client->onboarding_checklist['superops_scim_provisioning']);
    }
}
