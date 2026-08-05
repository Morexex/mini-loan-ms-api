<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }

    public function test_ops_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ops@miniloan.test',
            'password' => 'password',
        ]);

        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'ops@miniloan.test');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ops@miniloan.test',
            'password' => 'password',
        ]);

        $response = $this->withHeaders($this->spaHeaders())->postJson('/api/v1/login', [
            'email' => 'ops@miniloan.test',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = User::factory()->create([
            'email' => 'ops@miniloan.test',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('data.email', 'ops@miniloan.test');
    }

    public function test_guest_cannot_fetch_me(): void
    {
        $this->withHeaders($this->spaHeaders())
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/logout')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_receives_logout_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');
    }
}
