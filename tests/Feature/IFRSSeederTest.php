<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IFRSSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed in the same order DatabaseSeeder uses in production. UserSeeder
     * creating the admin BEFORE IFRSSeeder runs is exactly the scenario where
     * the old firstOrCreate-based association silently failed (its second
     * argument only applies on create), and where the missing entity_id
     * fillable dropped the attribute entirely.
     */
    private function seedInProductionOrder(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(IFRSSeeder::class);
    }

    public function test_seeder_associates_admin_user_with_base_entity_and_currency(): void
    {
        $this->seedInProductionOrder();

        // The admin user is associated with the base entity.
        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertNotNull($admin->entity_id, 'Admin user must be associated with the IFRS entity.');
        $this->assertEquals('Professional Services Company', $admin->entity->name);

        // The base entity exists with the AUD reporting currency attached,
        // and the currency belongs to the entity (no throwaway linkage).
        $entity = Entity::where('name', 'Professional Services Company')->first();
        $this->assertNotNull($entity);
        $this->assertNotNull($entity->currency_id, 'Entity must have a reporting currency.');
        $aud = Currency::find($entity->currency_id);
        $this->assertEquals('AUD', $aud->currency_code);
        $this->assertEquals($entity->id, $aud->entity_id);

        // The entity's AU financial year starts July, per config.
        $this->assertEquals(7, $entity->year_start);

        // No throwaway _TEMP_ entities remain.
        $this->assertSame(0, Entity::where('name', '_TEMP_')->count());

        // A reporting period exists for the entity's current year so
        // transactions can actually be posted (Transaction::save() requires
        // one or throws MissingReportingPeriod).
        $year = ReportingPeriod::year(now(), $entity);
        $this->assertDatabaseHas('ifrs_reporting_periods', [
            'entity_id' => $entity->id,
            'calendar_year' => $year,
            'status' => ReportingPeriod::OPEN,
        ]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedInProductionOrder();
        $this->seed(IFRSSeeder::class); // immediate re-run

        $this->assertSame(1, Entity::where('name', 'Professional Services Company')->count());
        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
        $this->assertSame(
            1,
            Currency::where('currency_code', 'AUD')->count(),
            'AUD currency must not be duplicated on re-run'
        );
    }
}
