<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvatarInitialTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_initial_is_multibyte_safe(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Bus::fake();

        $client = Client::factory()->create();
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'name' => 'élodie Ørsted',
        ]);

        $html = $this->actingAs($user)->get(route('support.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/aria-label="Account menu"[^>]*>\s*É\s*<\/button>/u', $html);
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'Page must not contain a split multibyte character.');
    }
}
