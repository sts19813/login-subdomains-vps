<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'google-client');
        config()->set('services.google.client_secret', 'google-secret');
        config()->set('services.google.redirect', 'https://naboo.cloud/auth/google/callback');
    }

    public function test_google_redirect_stores_state_and_uses_the_central_callback(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $query = [];
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame('https://naboo.cloud/auth/google/callback', $query['redirect_uri']);
        $this->assertSame(session('google_oauth_state'), $query['state']);
    }

    public function test_verified_google_profile_creates_a_central_identity(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-123',
                'email' => 'persona@example.com',
                'email_verified' => true,
                'name' => 'Persona Demo',
                'picture' => 'https://images.example.com/avatar.png',
            ]),
        ]);

        $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', ['state' => 'valid-state', 'code' => 'google-code']))
            ->assertRedirect(route('workspaces.index'));

        $user = User::query()->where('email', 'persona@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->google_id);
        $this->assertTrue($user->is_active);
    }

    public function test_google_callback_rejects_an_invalid_state(): void
    {
        Http::fake();

        $this->withSession(['google_oauth_state' => 'expected-state'])
            ->get(route('auth.google.callback', ['state' => 'other-state', 'code' => 'google-code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        Http::assertNothingSent();
        $this->assertGuest();
    }
}
