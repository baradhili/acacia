<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppliers_index_lists_alphabetically(): void
    {
        $user = User::factory()->create();

        // Inserted out of order — the list is a lookup surface and
        // must come back alphabetical by name.
        Supplier::create(['name' => 'Zebra Supplies']);
        Supplier::create(['name' => 'Acme Freight']);
        Supplier::create(['name' => 'Marlin Consulting']);

        $response = $this->actingAs($user)->get(route('suppliers.index'));

        $response->assertOk();

        $this->assertEquals(
            ['Acme Freight', 'Marlin Consulting', 'Zebra Supplies'],
            $response->viewData('suppliers')->pluck('name')->all(),
        );
    }
}
