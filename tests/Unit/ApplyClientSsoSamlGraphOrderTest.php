<?php

namespace Tests\Unit;

use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApplyClientSsoSamlGraphOrderTest extends TestCase
{
    public function test_v2_tokens_are_set_before_the_superops_entity_id_so_the_certificate_is_created(): void
    {
        config([
            'services.entra_sync.client_id' => 'portal-app',
            'services.entra_sync.client_secret' => 'portal-secret',
        ]);
        Cache::flush();

        $tenantId = '11111111-2222-3333-4444-555555555555';
        $appId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entityId = 'https://clientuser.superops.ai/saml/7570229319903653888/meta';
        $acs = 'https://portal.onit.ltd/accounts-web/accounts/saml/response/5884471812792168448';

        $patches = [];

        Http::fake(function ($request) use (&$patches, $appId) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, '/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'tok'], 200);
            }

            if ($method === 'GET' && str_contains($url, '/applications')) {
                return Http::response([
                    'value' => [['id' => 'app-obj', 'appId' => $appId]],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, 'servicePrincipals(appId=')) {
                return Http::response(['id' => 'sp-1', 'appId' => $appId], 200);
            }

            if ($method === 'GET' && str_contains($url, '/claimsMappingPolicies')) {
                return Http::response([
                    'value' => [['id' => 'pol-1', 'displayName' => 'OnIT SuperOps Client SSO claims']],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, 'keyCredentials')) {
                return Http::response([
                    'id' => 'sp-1',
                    'keyCredentials' => [[
                        'usage' => 'Sign',
                        'type' => 'AsymmetricX509Cert',
                        'key' => 'QUJD',
                    ]],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/servicePrincipals/')) {
                return Http::response(['id' => 'sp-1'], 200);
            }

            if ($method === 'PATCH') {
                $patches[] = $request->data();

                return Http::response([], 204);
            }

            return Http::response([], 204);
        });

        $result = app(MicrosoftGraphClient::class)->applyClientSsoSamlConfiguration(
            $tenantId,
            $appId,
            $entityId,
            $acs,
        );

        $tokenAt = null;
        $entityAt = null;
        foreach ($patches as $index => $body) {
            if (($body['api']['requestedAccessTokenVersion'] ?? null) === 2) {
                $tokenAt = $index;
            }
            if (($body['identifierUris'][0] ?? null) === $entityId) {
                $entityAt = $index;
            }
        }

        $this->assertNotNull($tokenAt);
        $this->assertNotNull($entityAt);
        $this->assertLessThan($entityAt, $tokenAt);
        $this->assertSame('https://login.microsoftonline.com/'.$tenantId.'/saml2', $result['loginUrl']);
        $this->assertSame('QUJD', $result['certificateBase64']);
    }
}
