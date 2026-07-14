<?php

namespace Tests\Feature;

use App\Models\SsoCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SsoAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_uses_the_naboo_authentication_layout(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Todo tu portafolio.')
            ->assertSee('Iniciar sesión')
            ->assertSee('assets/css/auth.css', false)
            ->assertSee('assets/img/naboo-logo-white.svg', false);
    }

    public function test_authenticated_user_opening_root_is_sent_to_workspace_selector(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('workspaces.index'));
    }

    public function test_authenticated_user_cannot_loop_between_login_and_root(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect(route('workspaces.index'));
    }

    public function test_user_with_one_workspace_is_redirected_with_a_single_use_code(): void
    {
        $user = User::factory()->create([
            'email' => 'usuario@naboo.cloud',
            'password' => Hash::make('secret123'),
        ]);
        $workspace = $this->workspace('tipi');
        $user->workspaces()->attach($workspace, ['is_active' => true]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith($workspace->callback_url.'?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('tipi', $query['workspace']);
        $this->assertSame(80, strlen($query['code']));
        $this->assertDatabaseHas('sso_codes', [
            'code_hash' => hash('sha256', $query['code']),
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'consumed_at' => null,
        ]);
        $this->assertDatabaseMissing('sso_codes', ['code_hash' => $query['code']]);
    }

    public function test_user_with_multiple_workspaces_sees_the_selector(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $tipi = $this->workspace('tipi');
        $tayde = $this->workspace('tayde');
        $user->workspaces()->attach([
            $tipi->id => ['is_active' => true],
            $tayde->id => ['is_active' => true],
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertRedirect(route('workspaces.index'));

        $this->actingAs($user)
            ->get(route('workspaces.index'))
            ->assertOk()
            ->assertSee('Tipi')
            ->assertSee('Tayde');
    }

    public function test_requested_workspace_is_preserved_through_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $workspace = $this->workspace('tayde');
        $user->workspaces()->attach($workspace, ['is_active' => true]);

        $this->get(route('login', ['workspace' => 'tayde']))->assertOk();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $this->assertStringStartsWith($workspace->callback_url.'?', (string) $response->headers->get('Location'));
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'password' => Hash::make('secret123'),
        ]);

        $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_cannot_launch_an_unassigned_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace('tipi');

        $this->actingAs($user)
            ->post(route('workspaces.launch', $workspace))
            ->assertForbidden();

        $this->assertSame(0, SsoCode::query()->count());
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'base_url' => "https://{$slug}.naboo.cloud",
            'callback_url' => "https://{$slug}.naboo.cloud/sso/callback",
            'client_id' => "client-{$slug}",
            'client_secret_hash' => Hash::make("secret-{$slug}"),
            'is_active' => true,
        ]);
    }
}
