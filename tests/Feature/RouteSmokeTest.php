<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IfrsPosting;
use Database\Seeders\IFRSSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every parameterless GET route renders for an admin — one loop of the
 * route table instead of a screen-by-screen fight. Catches broken
 * views, missing seed dependencies and dead routes cheaply; the
 * untested create-form screens were the original driver. Parameterised
 * routes stay out (no sensible model to bind); their screens are
 * covered by the feature tests that exercise them.
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * GET routes that must not be hit as-is: the logout would strand
     * every later request as a guest, the arrilot widget loader is an
     * AJAX endpoint that expects a widget payload, and the documents
     * index is the AJAX attachment list requiring type/id parameters —
     * bare, they fail instead of rendering anything.
     */
    private const EXCLUDED_URIS = [
        'arrilot/load-widget',
        'documents',
    ];

    private const EXCLUDED_NAMES = [
        'logout',
    ];

    /**
     * Screens that deliberately abort(404) on a bare database because
     * their prerequisites are missing — the only routes allowed to
     * answer 404. Anything else 404ing is a broken route.
     */
    private const GUARDED_404_URIS = [
        'dividends/create',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        // The chart, vats and entity most accounting screens resolve.
        $this->seed(IFRSSeeder::class);
    }

    protected function admin(): User
    {
        $entity = IfrsPosting::resolveEntity();

        return tap(User::factory()->create(['entity_id' => $entity?->id]))->assignRole('admin');
    }

    public function test_every_parameterless_get_route_renders_for_an_admin(): void
    {
        $hits = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();
            if (str_contains($uri, '{') || str_starts_with($uri, 'api/') || str_contains($uri, 'livewire')) {
                continue;
            }

            if (in_array($route->getName(), self::EXCLUDED_NAMES, true)
                || in_array($uri, self::EXCLUDED_URIS, true)) {
                continue;
            }

            $response = $this->actingAs($this->admin())->get('/'.ltrim($uri, '/'));
            $hits++;

            // Only the deliberately guarded screens may 404 (dividends
            // aborts without a company profile and share classes) — a
            // 404 anywhere else is a broken route, and a 500 is always
            // a regression (the CRM lead-create screen shipped one).
            $allowed = in_array($uri, self::GUARDED_404_URIS, true)
                ? [200, 302, 303, 307, 404]
                : [200, 302, 303, 307];

            $this->assertContains(
                $response->getStatusCode(),
                $allowed,
                sprintf('GET %s (%s) returned %d', $uri, $route->getName(), $response->getStatusCode())
            );
        }

        // Guard the guard: an empty loop would pass vacuously. The
        // floor is half the current route count, not the count itself
        // — redundant screens get removed (the Time by Project report
        // took the table to exactly 100 and broke a >100 floor), and
        // the guard only needs to catch a vacuous or gutted loop.
        $this->assertGreaterThan(50, $hits, 'The smoke loop should have hit a meaningful number of routes.');
    }
}
