<?php

namespace Tests\Unit;

use App\Support\Nav;
use PHPUnit\Framework\TestCase;

/**
 * The icon guard is the boundary that keeps the sidebar's raw echo
 * (the @navIcon directive) safe — these pin its contract: allowlisted
 * inline-SVG shapes pass through untouched, anything carrying event
 * handlers, script content or disallowed elements degrades to the
 * neutral fallback, and empty icons stay empty (no spacer dot).
 */
class NavIconTest extends TestCase
{
    public function test_path_fragments_pass_through_untouched(): void
    {
        $icon = '<path stroke-linecap="round" d="M3 12l2-2"></path>';

        $this->assertSame($icon, Nav::renderIcon($icon));
    }

    public function test_allowlisted_shapes_pass(): void
    {
        foreach (['circle', 'rect', 'line', 'g', 'title'] as $shape) {
            $this->assertSame(
                "<{$shape}></{$shape}>",
                Nav::renderIcon("<{$shape}></{$shape}>"),
            );
        }
    }

    public function test_event_handlers_are_rejected(): void
    {
        $this->assertStringStartsWith('<circle', Nav::renderIcon('<path onload="alert(1)" d="M3 12"></path>'));
    }

    public function test_script_and_disallowed_elements_are_rejected(): void
    {
        $this->assertStringStartsWith('<circle', Nav::renderIcon('<path d="M3 12"></path><script>alert(1)</script>'));

        $this->assertStringStartsWith('<circle', Nav::renderIcon('<iframe src="x"></iframe>'));

        $this->assertStringStartsWith('<circle', Nav::renderIcon('<path d="M3 12" href="javascript:alert(1)"></path>'));
    }

    public function test_empty_and_null_render_nothing(): void
    {
        $this->assertSame('', Nav::renderIcon(null));
        $this->assertSame('', Nav::renderIcon(''));
        $this->assertSame('', Nav::renderIcon('   '));
    }

    public function test_plain_text_is_rejected(): void
    {
        // Not markup at all — must not be emitted raw.
        $this->assertStringStartsWith('<circle', Nav::renderIcon('Search'));
    }
}
