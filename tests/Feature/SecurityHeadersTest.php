<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_hardening_headers(): void
    {
        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_maintenance_responses_carry_hardening_headers(): void
    {
        // The middleware is prepended so it wraps
        // PreventRequestsDuringMaintenance — a 503 must still be
        // hardened, and it renders the custom errors/503 view.
        $down = storage_path('framework/down');
        file_put_contents($down, json_encode(['retry_at' => now()->addMinutes(10)->getTimestamp()]));

        try {
            $this->get('/login')
                ->assertStatus(503)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        } finally {
            @unlink($down);
        }
    }
}
