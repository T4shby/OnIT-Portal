<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_consent_url_uses_tenant_and_app_client_id(): void
    {
        config(['services.entra_sync.client_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $url = app(ClientOnboardingService::class)->adminConsentUrl($client);

        $this->assertSame(
            'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/adminconsent?client_id=aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            $url,
        );
    }

    public function test_progress_counts_auto_completed_steps(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => '12345',
            'pax8_sso_enabled' => false,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
            'entra_sync_enabled' => true,
            'entra_synced_at' => now(),
        ]);

        $progress = app(ClientOnboardingService::class)->progress($client);

        $this->assertGreaterThanOrEqual(6, $progress['complete']);
        $this->assertSame(11, $progress['total']);
    }

    public function test_unsaved_client_does_not_mark_portal_record_complete(): void
    {
        $client = new Client(['name' => 'Draft Co']);

        $steps = collect(app(ClientOnboardingService::class)->steps($client));

        $this->assertFalse($steps->firstWhere('key', 'portal_client_created')['complete']);
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

    public function test_field_helps_include_entra_ids(): void
    {
        $helps = app(ClientOnboardingService::class)->fieldHelps();

        $this->assertArrayHasKey('entra_tenant_id', $helps);
        $this->assertArrayHasKey('entra_group_id', $helps);
        $this->assertArrayHasKey('pax8_company_id', $helps);
        $this->assertNotEmpty($helps['entra_tenant_id']);
        $this->assertNotEmpty($helps['pax8_company_id']);
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
        $this->assertFalse($client->onboarding_checklist['handed_off']);
    }
}
