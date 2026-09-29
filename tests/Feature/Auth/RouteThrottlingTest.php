<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brute-force gates on the guest auth routes: login/register POSTs
 * allow five attempts per minute per IP, the form pages sixty — loose
 * enough that an office behind one NAT can still reach the forms.
 */
class RouteThrottlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_registration_attempts_are_rate_limited(): void
    {
        // Invalid payloads keep the requester a guest, so every attempt
        // reaches the route under test instead of the auth redirect.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/register', [
                'name' => 'Bot',
                'email' => "bot-{$attempt}@example.com",
            ]);
        }

        $this->post('/register', [
            'name' => 'Bot',
            'email' => 'bot-final@example.com',
        ])->assertStatus(429);
    }

    public function test_login_screen_is_reachable_but_not_hammerable(): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->get('/login')->assertOk();
        }

        $this->get('/login')->assertStatus(429);
    }

    public function test_form_views_do_not_drain_the_credential_budget(): void
    {
        // The named limiters must keep separate buckets: with the
        // default domain|ip throttle key, five form views would leave
        // the next POST login at its ceiling and 429.
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->get('/login')->assertOk();
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(302)->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
