<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpenseValidationTest extends TestCase
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

    public function test_category_is_required(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'amount' => 100.00,
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('category');
    }

    public function test_amount_is_required(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_amount_must_be_numeric(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 'not-a-number',
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_amount_must_not_be_negative(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => -50.00,
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_date_is_required(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 100.00,
        ]);

        $response->assertSessionHasErrors('expense_date');
    }

    public function test_date_must_be_valid_date(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 100.00,
            'expense_date' => 'not-a-date',
        ]);

        $response->assertSessionHasErrors('expense_date');
    }

    public function test_due_date_must_be_valid_when_provided(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 100.00,
            'expense_date' => now()->format('Y-m-d'),
            'due_date' => 'invalid-date',
        ]);

        $response->assertSessionHasErrors('due_date');
    }

    public function test_supplier_must_exist(): void
    {
        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => 99999,
            'category' => 'travel',
            'amount' => 100.00,
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('supplier_id');
    }

    public function test_valid_expense_can_be_created(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 100.00,
            'expense_date' => now()->format('Y-m-d'),
            'description' => 'Business trip',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 100.00,
        ]);
    }

    public function test_amount_must_be_at_least_one_cent(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 0,
            'expense_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_due_date_can_be_null(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $client->id,
            'category' => 'travel',
            'amount' => 100.00,
            'expense_date' => now()->format('Y-m-d'),
            'due_date' => null,
        ]);

        $response->assertSessionHasNoErrors();
    }
}
