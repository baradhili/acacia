<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The custom 500/503 pages are standalone (no layout, no built assets)
 * so they render when the app is broken — which also means nothing
 * else exercises them. Render both directly to prove the Blade
 * compiles and the translator keys resolve.
 */
class CustomErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_pages_render_their_translated_copy(): void
    {
        // {{ }} escapes, so the expectation must be the escaped form.
        $this->assertStringContainsString(
            e(__('errors.server_error_title')),
            view('errors/500')->render(),
        );

        $this->assertStringContainsString(
            e(__('errors.maintenance_title')),
            view('errors/503')->render(),
        );
    }
}
