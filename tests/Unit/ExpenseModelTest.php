<?php

namespace Tests\Unit;

use App\Models\Supplier;
use App\Models\Document;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpenseModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_table_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('expenses');
        
        $this->assertContains('category', $columns);
        $this->assertContains('amount', $columns);
        $this->assertContains('supplier_id', $columns);
        $this->assertContains('status', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_expenses_table_has_optional_date_columns(): void
    {
        $columns = Schema::getColumnListing('expenses');
        
        $this->assertContains('expense_date', $columns);
        $this->assertContains('due_date', $columns);
    }

    public function test_expenses_table_has_total_column(): void
    {
        $columns = Schema::getColumnListing('expenses');
        
        $this->assertContains('total', $columns);
    }

    public function test_amount_is_cast_as_decimal(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
            'amount' => 100.50,
        ]);

        $expense->refresh();
        
        $this->assertIsString($expense->amount);
        $this->assertEquals('100.50', $expense->amount);
    }

    public function test_total_is_cast_as_decimal(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
            'amount' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        $expense->refresh();
        
        $this->assertIsString($expense->total);
        $this->assertEquals('110.00', $expense->total);
    }

    public function test_expense_date_is_cast_as_date(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
            'expense_date' => '2024-06-15',
        ]);

        $expense->refresh();
        
        $this->assertInstanceOf(\Carbon\Carbon::class, $expense->expense_date);
        $this->assertEquals('2024-06-15', $expense->expense_date->toDateString());
    }

    public function test_due_date_is_cast_as_date(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
            'due_date' => '2024-07-15',
        ]);

        $expense->refresh();
        
        $this->assertInstanceOf(\Carbon\Carbon::class, $expense->due_date);
        $this->assertEquals('2024-07-15', $expense->due_date->toDateString());
    }

    public function test_expense_belongs_to_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
        ]);

        $this->assertInstanceOf(Supplier::class, $expense->supplier);
        $this->assertEquals($supplier->id, $expense->supplier->id);
    }

    public function test_expense_can_have_many_documents(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);

        Document::factory()->count(3)->create([
            'documentable_type' => 'App\\Models\\Expense',
            'documentable_id' => $expense->id,
        ]);

        $this->assertCount(3, $expense->documents);
        $this->assertInstanceOf(Document::class, $expense->documents->first());
    }

    public function test_expense_status_can_be_set(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => Expense::STATUS_DRAFT,
        ]);

        $this->assertEquals(Expense::STATUS_DRAFT, $expense->status);
    }

    public function test_expense_paid_status_exists(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->paid()->create(['supplier_id' => $supplier->id]);

        $this->assertEquals(Expense::STATUS_PAID, $expense->status);
    }

    public function test_allowed_status_values(): void
    {
        $statuses = [
            Expense::STATUS_DRAFT,
            Expense::STATUS_SUBMITTED,
            Expense::STATUS_APPROVED,
            Expense::STATUS_PAID,
            Expense::STATUS_CANCELLED,
        ];

        $this->assertContains('draft', $statuses);
        $this->assertContains('submitted', $statuses);
        $this->assertContains('approved', $statuses);
        $this->assertContains('paid', $statuses);
        $this->assertContains('cancelled', $statuses);
    }

    public function test_total_matches_amount_plus_tax(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create([
            'supplier_id' => $supplier->id,
            'amount' => 100,
            'tax_amount' => 10,
            'total' => 110,
        ]);

        $expense->refresh();
        
        $this->assertEquals('110.00', $expense->total);
    }

    public function test_expense_can_be_soft_deleted(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->draft()->create(['supplier_id' => $supplier->id]);
        $expenseId = $expense->id;

        $expense->delete();

        $this->assertSoftDeleted('expenses', ['id' => $expenseId]);
    }

    public function test_expense_factory_creates_valid_instance(): void
    {
        $expense = Expense::factory()->create();
        
        $this->assertNotNull($expense->category);
        $this->assertNotNull($expense->amount);
        $this->assertNotNull($expense->supplier_id);
        $this->assertNotNull($expense->status);
    }

    public function test_expense_has_status_constants(): void
    {
        $this->assertEquals('draft', Expense::STATUS_DRAFT);
        $this->assertEquals('submitted', Expense::STATUS_SUBMITTED);
        $this->assertEquals('approved', Expense::STATUS_APPROVED);
        $this->assertEquals('paid', Expense::STATUS_PAID);
        $this->assertEquals('cancelled', Expense::STATUS_CANCELLED);
    }

    public function test_expense_has_category_constants(): void
    {
        $categories = Expense::CATEGORIES;
        
        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
    }
}
