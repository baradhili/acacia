<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WidgetPreference;
use App\Support\Widgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardWidgetLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    /** The dashboard's grid segment — between the grid and the hidden store. */
    private function gridHtml(string $html): string
    {
        $start = strpos($html, 'id="widget-grid"');
        $end = strpos($html, 'id="widget-store"');
        $this->assertNotFalse($start, 'dashboard has no widget grid');
        $this->assertNotFalse($end, 'dashboard has no widget store');

        return substr($html, $start, $end - $start);
    }

    /** Assert the expected widget ids render in this relative order (extras may interleave). */
    private function assertGridOrder(string $html, array $expected): void
    {
        preg_match_all('/data-widget="([^"]+)"/', $this->gridHtml($html), $matches);
        $actual = array_values(array_intersect($matches[1], $expected));

        $this->assertEquals($expected, $actual, sprintf(
            'Expected grid order [%s] but the grid renders [%s]',
            implode(', ', $expected),
            implode(', ', $matches[1]),
        ));
    }

    public function test_default_dashboard_renders_the_registry_order(): void
    {
        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();

        // The shipped grid: core positions with the module widgets in
        // their slots — Crm's pipeline now deliberately at the tail
        // instead of the registry-default front.
        $this->assertGridOrder($html, [
            'TotalClientsWidget', 'OutstandingInvoicesWidget', 'HoursThisMonthWidget',
            'GstPayableWidget', 'CashFlowWidget', 'ARAgingWidget', 'BankBalanceWidget',
            'RecentInvoicesWidget', 'RecentPaymentsWidget', 'OutstandingPOBudgetsWidget',
            'UnbilledTimeWidget', 'PnLTrendWidget', 'PipelineWidget',
        ]);

        // No saved layout: nothing parks in the removed-widgets store.
        $storeStart = strpos($html, 'id="widget-store"');
        $store = substr($html, $storeStart, strpos($html, 'id="widget-catalog"') - $storeStart);
        $this->assertStringNotContainsString('data-widget=', $store);
    }

    public function test_full_save_reorders_hides_and_resizes(): void
    {
        // A full save names every registered widget — the rest of the
        // registry's ids ride along after the ones under test, in
        // registry order.
        $rest = array_diff(app(Widgets::class)->ids(), ['PnLTrendWidget', 'TotalClientsWidget', 'CashFlowWidget']);
        $payload = [
            ['widget_name' => 'PnLTrendWidget', 'visible' => true, 'width' => 4],
            ['widget_name' => 'TotalClientsWidget', 'visible' => true],
            ['widget_name' => 'CashFlowWidget', 'visible' => false],
            ...array_map(fn (string $id) => ['widget_name' => $id, 'visible' => true], array_values($rest)),
        ];

        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', ['widgets' => $payload, 'complete' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $rows = WidgetPreference::where('user_id', $this->user->id)->get();
        $this->assertSame(count($payload), $rows->count());
        $this->assertSame(0, (int) $rows->firstWhere('widget_name', 'PnLTrendWidget')->position_y);
        $this->assertSame(2, (int) $rows->firstWhere('widget_name', 'CashFlowWidget')->position_y);

        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();

        // Saved positions render first in drag order; everything the
        // save didn't place follows in registry order; the hidden
        // widget is out of the grid but listed in the catalog.
        $this->assertGridOrder($html, ['PnLTrendWidget', 'TotalClientsWidget', 'OutstandingInvoicesWidget']);
        $this->assertStringNotContainsString('data-widget="CashFlowWidget"', $this->gridHtml($html));
        $this->assertStringContainsString('Cash Flow (30 Days)', $html);

        // Width 4 overrides the shipped span (and 0 keeps it, on
        // TotalClients' class-less card). The card's attributes span
        // lines, so match on whitespace-normalised markup.
        $flat = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString(
            'class="widget-card relative md:col-span-2 lg:col-span-4" data-widget="PnLTrendWidget"',
            $flat,
        );
        $this->assertStringContainsString('class="widget-card relative " data-widget="TotalClientsWidget"', $flat);
    }

    public function test_single_widget_patch_hides_and_readds_in_registry_order(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/widget-preferences', ['widget_name' => 'CashFlowWidget', 'visible' => false])
            ->assertOk();

        $grid = $this->gridHtml($this->actingAs($this->user)->get('/dashboard')->getContent());
        $this->assertStringNotContainsString('data-widget="CashFlowWidget"', $grid);

        // A patch never reorders: re-adding returns the widget to its
        // registry slot (position_y stays NULL), not to the front —
        // neighbours prove placement, not just presence.
        $this->actingAs($this->user)
            ->putJson('/api/widget-preferences', ['widget_name' => 'CashFlowWidget', 'visible' => true])
            ->assertOk();

        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertGridOrder($html, ['GstPayableWidget', 'CashFlowWidget', 'ARAgingWidget']);
        $this->assertNull(
            WidgetPreference::where('user_id', $this->user->id)
                ->where('widget_name', 'CashFlowWidget')
                ->value('position_y'),
        );
    }

    public function test_full_save_rejects_unknown_widget_names(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'NotAWidget', 'visible' => true],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('widgets.0.widget_name');

        $this->assertSame(0, WidgetPreference::count());
    }

    public function test_full_save_drops_rows_for_widgets_that_left_the_registry(): void
    {
        // user_id is an FK outside WidgetPreference's $fillable
        // (mass-assignment hardening) — ownership is assigned directly.
        $preference = new WidgetPreference;
        $preference->fill([
            'widget_name' => 'DisabledModuleWidget',
            'visible' => true,
        ]);
        $preference->user_id = $this->user->id;
        $preference->save();

        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'TotalClientsWidget', 'visible' => true],
                ],
            ])
            ->assertOk();

        $this->assertNull(WidgetPreference::where('widget_name', 'DisabledModuleWidget')->first());
    }

    /**
     * The stale-row cleanup targets widgets that left the registry,
     * not widgets the payload omitted — a partial save rewrites only
     * what it mentions.
     */
    public function test_partial_save_leaves_unmentioned_widgets_untouched(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'PnLTrendWidget', 'visible' => true, 'width' => 2],
                    ['widget_name' => 'CashFlowWidget', 'visible' => false],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'TotalClientsWidget', 'visible' => true],
                ],
            ])
            ->assertOk();

        $pnL = WidgetPreference::where('widget_name', 'PnLTrendWidget')->first();
        $this->assertNotNull($pnL);
        $this->assertSame(2, (int) $pnL->width);
        $this->assertFalse((bool) WidgetPreference::where('widget_name', 'CashFlowWidget')->value('visible'));
    }

    /**
     * Ordering comes from full saves only: a partial payload's
     * 0-based indices must never be written — they would collide
     * with existing positions (a saved position 0 claimed by another
     * widget) and ties would silently fall back to registry order,
     * reporting success for an order that never applied.
     */
    public function test_partial_save_never_writes_positions(): void
    {
        $rest = array_diff(app(Widgets::class)->ids(), ['TotalClientsWidget']);
        $payload = [
            ['widget_name' => 'TotalClientsWidget', 'visible' => true],
            ...array_map(fn (string $id) => ['widget_name' => $id, 'visible' => true], array_values($rest)),
        ];
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', ['widgets' => $payload, 'complete' => true])
            ->assertOk();
        $this->assertSame(0, (int) WidgetPreference::where('widget_name', 'TotalClientsWidget')->value('position_y'));

        // Claims position 0 for a widget the full save placed
        // elsewhere — a patch, so nothing moves.
        $placed = (int) WidgetPreference::where('widget_name', 'PnLTrendWidget')->value('position_y');
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'PnLTrendWidget', 'visible' => true],
                ],
            ])
            ->assertOk();

        $this->assertSame($placed, (int) WidgetPreference::where('widget_name', 'PnLTrendWidget')->value('position_y'));
        $this->assertSame(0, (int) WidgetPreference::where('widget_name', 'TotalClientsWidget')->value('position_y'));
    }

    /**
     * The registry can grow between page load and Done (a module
     * enabled mid-session): the browser still submits every card it
     * loaded — fewer than the registry now holds — and declares the
     * payload complete. The drag order must survive that save; the
     * widget the page never saw rides at the tail.
     */
    public function test_complete_save_keeps_order_when_the_registry_grew(): void
    {
        // Three of the registry's widgets — what a page rendered
        // before the rest registered would submit.
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'complete' => true,
                'widgets' => [
                    ['widget_name' => 'PnLTrendWidget', 'visible' => true],
                    ['widget_name' => 'TotalClientsWidget', 'visible' => true],
                    ['widget_name' => 'CashFlowWidget', 'visible' => false],
                ],
            ])
            ->assertOk();

        $this->assertSame(0, (int) WidgetPreference::where('widget_name', 'PnLTrendWidget')->value('position_y'));
        $this->assertSame(1, (int) WidgetPreference::where('widget_name', 'TotalClientsWidget')->value('position_y'));

        // The saved order renders; widgets the page never loaded
        // (every one the payload omitted) follow in registry order.
        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertGridOrder($html, ['PnLTrendWidget', 'TotalClientsWidget', 'OutstandingInvoicesWidget', 'PipelineWidget']);
    }

    /**
     * The registry can also swap between page load and Done: a widget
     * unregistered when the page rendered (no card in the DOM) but
     * back in the registry by save time keeps its old preference row
     * — the cleanup spares registered widgets. Its stale position
     * must not survive to collide with the freshly written sequence;
     * it unplaces and rides at the tail in registry order.
     */
    public function test_complete_save_unplaces_widgets_that_left_and_returned(): void
    {
        // An earlier layout with GstPayableWidget placed mid-sequence.
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'complete' => true,
                'widgets' => [
                    ['widget_name' => 'TotalClientsWidget', 'visible' => true],
                    ['widget_name' => 'GstPayableWidget', 'visible' => true],
                    ['widget_name' => 'PnLTrendWidget', 'visible' => true],
                ],
            ])
            ->assertOk();
        $this->assertSame(1, (int) WidgetPreference::where('widget_name', 'GstPayableWidget')->value('position_y'));

        // A page rendered while the widget was unregistered (its
        // module disabled) submits the cards it loaded; the widget
        // re-registered before Done, so it is in the registry again
        // and the save must not collide with its old index of 1.
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'complete' => true,
                'widgets' => [
                    ['widget_name' => 'PnLTrendWidget', 'visible' => true],
                    ['widget_name' => 'TotalClientsWidget', 'visible' => true],
                ],
            ])
            ->assertOk();

        $this->assertSame(0, (int) WidgetPreference::where('widget_name', 'PnLTrendWidget')->value('position_y'));
        $this->assertSame(1, (int) WidgetPreference::where('widget_name', 'TotalClientsWidget')->value('position_y'));
        $this->assertNull(WidgetPreference::where('widget_name', 'GstPayableWidget')->value('position_y'));

        // The dashboard shows exactly what the user saved first, the
        // returning widget after — not an interleaving its stale
        // index would have produced.
        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertGridOrder($html, ['PnLTrendWidget', 'TotalClientsWidget', 'OutstandingInvoicesWidget', 'HoursThisMonthWidget', 'GstPayableWidget', 'CashFlowWidget']);
    }

    public function test_full_save_rejects_keyed_and_duplicated_widgets(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', ['widgets' => ['foo' => ['widget_name' => 'TotalClientsWidget', 'visible' => true]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('widgets');

        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'TotalClientsWidget', 'visible' => true],
                    ['widget_name' => 'TotalClientsWidget', 'visible' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('widgets.1.widget_name');
    }

    public function test_layouts_are_per_user(): void
    {
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'TotalClientsWidget', 'visible' => false],
                ],
            ])
            ->assertOk();

        // The other user's dashboard is untouched by the first user's
        // layout.
        $grid = $this->gridHtml($this->actingAs($other)->get('/dashboard')->getContent());
        $this->assertStringContainsString('data-widget="TotalClientsWidget"', $grid);
    }

    public function test_reset_restores_the_default_layout(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/widget-preferences', [
                'widgets' => [
                    ['widget_name' => 'TotalClientsWidget', 'visible' => false],
                    ['widget_name' => 'PnLTrendWidget', 'visible' => true, 'width' => 2],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->deleteJson('/api/widget-preferences/reset')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, WidgetPreference::where('user_id', $this->user->id)->count());

        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertStringContainsString('data-widget="TotalClientsWidget"', $this->gridHtml($html));
    }

    public function test_preference_endpoints_require_authentication(): void
    {
        // The api/* prefix renders exceptions as JSON (bootstrap/app.php),
        // so unauthenticated requests get 401s rather than login redirects.
        $this->get('/api/widget-preferences')->assertStatus(401);
        $this->get('/api/widget-preferences/hidden-widgets')->assertStatus(401);
        $this->postJson('/api/widget-preferences', [])->assertStatus(401);
    }

    /**
     * The store is lazy: the dashboard page never renders removed
     * cards (their queries must not run on dashboard loads) — they
     * come from the hidden-widgets endpoint when edit mode opens.
     */
    public function test_hidden_cards_render_only_via_the_lazy_endpoint(): void
    {
        $storeSegment = function (string $html): string {
            $start = strpos($html, 'id="widget-store"');

            return substr($html, $start, strpos($html, 'id="widget-catalog"') - $start);
        };

        $this->actingAs($this->user)
            ->putJson('/api/widget-preferences', ['widget_name' => 'CashFlowWidget', 'visible' => false])
            ->assertOk();

        $page = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('data-widget=', $storeSegment($page));

        $this->actingAs($this->user)
            ->get('/api/widget-preferences/hidden-widgets')
            ->assertOk()
            ->assertSee('data-widget="CashFlowWidget"', false);
    }

    /**
     * The catalog contract: every registered widget carries a label
     * key that resolves — an unlabelled registration falls back to its
     * id, which never translates, so this fails rather than showing a
     * class basename to users.
     */
    public function test_every_registered_widget_label_resolves(): void
    {
        foreach (app(Widgets::class)->all() as $widget) {
            $this->assertNotSame(
                $widget['label'],
                __($widget['label']),
                "{$widget['id']} registers label '{$widget['label']}' with no translation",
            );
        }
    }

    /**
     * The locale contract from lang/README.md, proven on the rendered
     * dashboard: en is the complete base, en_AU overrides only the
     * differing keys (Aging → Ageing) and falls back per key.
     */
    public function test_widget_strings_render_the_en_au_override_and_fall_back_per_key(): void
    {
        app()->setLocale('en_AU');
        $au = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertStringContainsString('AR Ageing Summary', $au);
        // No en_AU override — resolves from en.
        $this->assertStringContainsString('Cash Flow (30 Days)', $au);
        $this->assertStringContainsString('Ageing Bucket', $au);
        $this->assertStringNotContainsString('AR Aging Summary', $au);

        app()->setLocale('en');
        $en = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $this->assertStringContainsString('AR Aging Summary', $en);
        $this->assertStringContainsString('Aging Bucket', $en);
    }
}
