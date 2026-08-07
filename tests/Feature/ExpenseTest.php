<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'accountant']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_expense_index_requires_authentication(): void
    {
        $response = $this->get('/expenses');
        $response->assertRedirect('/login');
    }

    public function test_expense_index_shows_expenses(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
        ]);

        $response = $this->actingAs($this->user)->get('/expenses');
        $response->assertStatus(200);
        $response->assertSee($expense->reference ?? $expense->id);
    }

    public function test_can_create_expense(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAs($this->user)->post('/expenses', [
            'supplier_id' => $supplier->id,
            'category' => 'travel',
            'amount' => 100.00,
            'tax_amount' => 10.00,
            'expense_date' => Carbon::now()->format('Y-m-d'),
            'description' => 'Business travel',
            'reference' => 'TRV-001',
        ]);

        $response->assertRedirect('/expenses/1');
        $this->assertDatabaseHas('expenses', [
            'supplier_id' => $supplier->id,
            'category' => 'travel',
            'amount' => 100.00,
            'total' => 110.00,
        ]);
    }

    public function test_can_view_expense(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
        ]);

        $response = $this->actingAs($this->user)->get("/expenses/{$expense->id}");
        $response->assertStatus(200);
        $response->assertSee($expense->reference ?? $expense->id);
    }

    public function test_can_update_expense(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create([
            'supplier_id' => $supplier->id,
            'amount' => 100.00,
        ]);

        $response = $this->actingAs($this->user)->put("/expenses/{$expense->id}", [
            'supplier_id' => $supplier->id,
            'category' => 'software',
            'amount' => 150.00,
            'tax_amount' => 15.00,
            'expense_date' => Carbon::now()->format('Y-m-d'),
        ]);

        $response->assertRedirect("/expenses/{$expense->id}");
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'category' => 'software',
            'amount' => 150.00,
        ]);
    }

    public function test_can_delete_draft_expense(): void
    {
        $expense = Expense::factory()->draft()->create();

        $response = $this->actingAs($this->user)->delete("/expenses/{$expense->id}");
        $response->assertRedirect('/expenses');
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_cannot_delete_paid_expense(): void
    {
        $expense = Expense::factory()->paid()->create();

        $response = $this->actingAs($this->user)->delete("/expenses/{$expense->id}");
        $response->assertRedirect('/expenses');
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'deleted_at' => null,
        ]);
    }

    public function test_expense_status_workflow(): void
    {
        $expense = Expense::factory()->create(['status' => Expense::STATUS_DRAFT]);
        
        // Submit
        $response = $this->actingAs($this->user)->post("/expenses/{$expense->id}/submit");
        $response->assertRedirect("/expenses/{$expense->id}");
        $expense->refresh();
        $this->assertEquals(Expense::STATUS_SUBMITTED, $expense->status);

        // Approve
        $response = $this->actingAs($this->user)->post("/expenses/{$expense->id}/approve");
        $response->assertRedirect("/expenses/{$expense->id}");
        $expense->refresh();
        $this->assertEquals(Expense::STATUS_APPROVED, $expense->status);

        // Pay
        $response = $this->actingAs($this->user)->post("/expenses/{$expense->id}/pay", [
            'payment_method' => 'bank_transfer',
        ]);
        $response->assertRedirect("/expenses/{$expense->id}");
        $expense->refresh();
        $this->assertEquals(Expense::STATUS_PAID, $expense->status);
        $this->assertNotNull($expense->paid_date);
    }

    public function test_can_cancel_expense(): void
    {
        $expense = Expense::factory()->draft()->create();

        $response = $this->actingAs($this->user)->post("/expenses/{$expense->id}/cancel");
        $response->assertRedirect("/expenses/{$expense->id}");
        $expense->refresh();
        $this->assertEquals(Expense::STATUS_CANCELLED, $expense->status);
    }

    public function test_cannot_cancel_paid_expense(): void
    {
        $expense = Expense::factory()->paid()->create();

        $response = $this->actingAs($this->user)->post("/expenses/{$expense->id}/cancel");
        $response->assertSessionHas('error');
    }

    public function test_expense_filters(): void
    {
        $supplier = Supplier::factory()->create();
        
        Expense::factory()->draft()->create(['supplier_id' => $supplier->id, 'category' => 'travel']);
        Expense::factory()->paid()->create(['supplier_id' => $supplier->id, 'category' => 'software']);

        // Filter by status
        $response = $this->actingAs($this->user)->get('/expenses?status=draft');
        $response->assertStatus(200);
        $response->assertSee('travel');

        // Filter by category
        $response = $this->actingAs($this->user)->get('/expenses?category=software');
        $response->assertStatus(200);
        $response->assertSee('software');
    }

    public function test_expense_categories_are_defined(): void
    {
        $categories = Expense::CATEGORIES;
        
        $this->assertIsArray($categories);
        $this->assertContains('travel', $categories);
        $this->assertContains('software', $categories);
        $this->assertContains('subcontractors', $categories);
    }

    public function test_expense_status_constants_are_defined(): void
    {
        $this->assertEquals('draft', Expense::STATUS_DRAFT);
        $this->assertEquals('submitted', Expense::STATUS_SUBMITTED);
        $this->assertEquals('approved', Expense::STATUS_APPROVED);
        $this->assertEquals('paid', Expense::STATUS_PAID);
        $this->assertEquals('cancelled', Expense::STATUS_CANCELLED);
    }
}
