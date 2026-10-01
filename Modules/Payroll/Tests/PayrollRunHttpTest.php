<?php

namespace Modules\Payroll\Tests;

use App\Models\User;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayRun;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The pay run lifecycle over HTTP — the money-moving routes the
 * service-level PayrollTest leaves unexercised: creating a run,
 * building and removing its payslips, processing (which posts the
 * accrual and payment journals), reversing, and deleting, plus the
 * admin/accountant gate and the create form's validation. Ledger
 * expectations mirror PayrollTest::test_processing_a_run_posts_the_three_journals.
 */
class PayrollRunHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        foreach (['admin', 'accountant', 'staff', 'client'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    protected function staff(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('staff');
    }

    protected function employee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'entity_id' => $this->entity->id,
            'name' => 'Jane Worker',
            'tfn' => '123456782',
            'employment_type' => Employee::TYPE_EMPLOYEE,
            'payment_basis' => Employee::BASIS_HOURLY,
            'hourly_rate' => 50,
            'tax_free_threshold' => true,
        ], $overrides));
    }

    protected function balance(int $code): float
    {
        $account = Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();

        return OpeningBalances::balanceAt($account, $this->entity, now());
    }

    /** A draft run with Jane's 76-hour payslip, created over HTTP. */
    protected function draftRunWithPayslip(): PayRun
    {
        $jane = $this->employee();

        $this->actingAs($this->admin())
            ->post('/payroll/runs', [
                'frequency' => 'fortnightly',
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-14',
                'payment_date' => '2026-09-15',
            ])
            ->assertRedirect();

        $run = PayRun::firstOrFail();

        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/payslips", [
                'employee_id' => $jane->id,
                'hours' => 76,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        return $run->refresh();
    }

    public function test_the_run_lifecycle_over_http(): void
    {
        $run = $this->draftRunWithPayslip();

        $this->assertSame(PayRun::STATUS_DRAFT, $run->status);
        $this->assertEquals(3800.0, (float) $run->payslips()->first()->gross);

        // Processing posts the journals: Dr expenses, Cr liabilities and
        // the bank; the wages-payable accrual nets to zero once the net
        // is paid (76h × $50 = $3,800 gross, PAYG $854, super $456).
        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/process")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($run->refresh()->isProcessed());
        $this->assertNotNull($run->ifrs_transaction_id);
        $this->assertEquals(3800.0, $this->balance(5100));
        $this->assertEquals(456.0, $this->balance(5150));
        $this->assertEquals(-854.0, $this->balance(2210));
        $this->assertEquals(-456.0, $this->balance(2220));
        $this->assertEquals(0.0, $this->balance(2235));
        $this->assertEquals(-2946.0, $this->balance(320));

        // Reversing takes the run back to draft and the ledger to zero.
        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/reverse")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($run->refresh()->isProcessed());
        foreach ([5100, 5150, 2210, 2220, 320] as $code) {
            $this->assertEquals(0.0, $this->balance($code));
        }
    }

    public function test_payslips_leave_draft_runs_only(): void
    {
        $run = $this->draftRunWithPayslip();
        $payslip = $run->payslips()->first();

        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/process")
            ->assertSessionHas('success');

        // A processed run's payslips are history — refuse the removal.
        $this->actingAs($this->admin())
            ->delete("/payroll/runs/{$run->id}/payslips/{$payslip->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('payslips', ['id' => $payslip->id]);

        // After reversing, the payslip goes.
        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/reverse")
            ->assertSessionHas('success');

        $this->actingAs($this->admin())
            ->delete("/payroll/runs/{$run->id}/payslips/{$payslip->id}")
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('payslips', ['id' => $payslip->id]);
    }

    public function test_runs_refuse_deletion_until_reversed(): void
    {
        $run = $this->draftRunWithPayslip();

        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/process")
            ->assertSessionHas('success');

        $this->actingAs($this->admin())
            ->delete("/payroll/runs/{$run->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('pay_runs', ['id' => $run->id]);

        $this->actingAs($this->admin())
            ->post("/payroll/runs/{$run->id}/reverse")
            ->assertSessionHas('success');

        $this->actingAs($this->admin())
            ->delete("/payroll/runs/{$run->id}")
            ->assertRedirect(route('payroll.index'))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('pay_runs', ['id' => $run->id]);
    }

    public function test_the_create_form_validates_its_payload(): void
    {
        $this->actingAs($this->admin())
            ->post('/payroll/runs', [
                'frequency' => 'whenever',
                'period_start' => '2026-09-14',
                'period_end' => '2026-09-01', // before the start
                'payment_date' => '2026-09-15',
            ])
            ->assertSessionHasErrors(['frequency', 'period_end']);

        $this->assertDatabaseCount('pay_runs', 0);
    }

    public function test_staff_is_forbidden_from_the_run_actions(): void
    {
        $run = $this->draftRunWithPayslip();
        $payslip = $run->payslips()->first();

        $this->actingAs($this->staff())->post('/payroll/runs', [
            'frequency' => 'fortnightly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-14',
            'payment_date' => '2026-09-15',
        ])->assertForbidden();

        $second = Employee::create([
            'entity_id' => $this->entity->id,
            'name' => 'Joe Blocked',
            'employment_type' => Employee::TYPE_EMPLOYEE,
            'payment_basis' => Employee::BASIS_HOURLY,
            'hourly_rate' => 40,
            'tax_free_threshold' => true,
        ]);
        $this->actingAs($this->staff())
            ->post("/payroll/runs/{$run->id}/payslips", [
                'employee_id' => $second->id,
                'hours' => 38,
            ])->assertForbidden();
        $this->assertDatabaseMissing('payslips', ['employee_id' => $second->id]);

        $this->actingAs($this->staff())->post("/payroll/runs/{$run->id}/process")->assertForbidden();
        $this->actingAs($this->staff())->post("/payroll/runs/{$run->id}/reverse")->assertForbidden();
        $this->actingAs($this->staff())->delete("/payroll/runs/{$run->id}")->assertForbidden();
        $this->actingAs($this->staff())
            ->delete("/payroll/runs/{$run->id}/payslips/{$payslip->id}")
            ->assertForbidden();

        $this->assertSame(PayRun::STATUS_DRAFT, $run->fresh()->status);
        $this->assertDatabaseHas('payslips', ['id' => $payslip->id]);
    }
}
