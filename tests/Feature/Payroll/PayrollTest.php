<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\FiscalPeriod;
use App\Models\PayRun;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Services\OpeningBalances;
use App\Services\PayrollService;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Australian payroll: PAYG withholding against the ATO's published
 * NAT 1004 examples, super guarantee (12% from 1 July 2026 — payday
 * super accrues per run), the director and labour-only-contractor
 * rules, and the three-journal posting with reversal. Closely linked
 * payees and PSI workers are flagged through to payslips.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected PayrollService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        $this->payroll = app(PayrollService::class);
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

    protected function runWithPayslip(Employee $employee, float $hours = 76): PayRun
    {
        $run = $this->payroll->createRun([
            'frequency' => 'fortnightly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-14',
            'payment_date' => '2026-09-15',
        ]);

        $this->payroll->addPayslip($run, $employee, $hours);

        return $run->refresh();
    }

    protected function balance(int $code): float
    {
        $account = Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();

        return OpeningBalances::balanceAt($account, $this->entity, now());
    }

    public function test_withholding_matches_the_ato_schedule_examples(): void
    {
        $jane = $this->employee();

        // ATO Scale 2 worked examples (2026-27 coefficients): weekly
        // $2,000 → $458 and monthly $8,666.67 → $1,984.67 come from
        // the weekly-coefficient conversion NAT 1004 specifies (weekly
        // $2,000, rounded, × 13 ÷ 3). Fortnightly figures below follow
        // the same conversion — the ATO's fortnightly-specific
        // coefficient table can differ by a dollar here and there.
        $this->assertSame(458.0, $this->payroll->withholding($jane, 2000, 'weekly'));
        $this->assertSame(138.0, $this->payroll->withholding($jane, 1000, 'weekly'));
        $this->assertSame(628.0, $this->payroll->withholding($jane, 3100, 'fortnightly'));
        $this->assertSame(164.0, $this->payroll->withholding($jane, 1600, 'fortnightly'));
        $this->assertSame(1984.67, $this->payroll->withholding($jane, 8666.67, 'monthly'));

        // Below the threshold band: nothing withheld.
        $this->assertSame(0.0, $this->payroll->withholding($jane, 300, 'weekly'));
    }

    public function test_withholding_uses_scale_1_without_the_tax_free_threshold(): void
    {
        $jane = $this->employee(['tax_free_threshold' => false]);

        $this->assertSame(264.0, $this->payroll->withholding($jane, 1000, 'weekly'));
    }

    public function test_no_tfn_withholds_47_percent(): void
    {
        $jane = $this->employee(['tfn' => null]);

        $this->assertSame(470.0, $this->payroll->withholding($jane, 1000, 'weekly'));
    }

    public function test_contractors_withhold_nothing_and_labour_only_contractors_still_earn_super(): void
    {
        $companyContractor = $this->employee([
            'employment_type' => Employee::TYPE_CONTRACTOR,
            'labour_only' => false,
        ]);
        $labourOnly = $this->employee([
            'employment_type' => Employee::TYPE_CONTRACTOR,
            'labour_only' => true,
        ]);

        $this->assertSame(0.0, $this->payroll->withholding($companyContractor, 2000, 'weekly'));
        $this->assertSame(0.0, $this->payroll->superContribution($companyContractor, 2000, now()));

        $this->assertSame(0.0, $this->payroll->withholding($labourOnly, 2000, 'weekly'));
        $this->assertSame(240.0, $this->payroll->superContribution($labourOnly, 2000, now()));
    }

    public function test_director_fees_withhold_and_earn_super(): void
    {
        // The spec's worked example: $5,000 monthly director fee → $600 SG.
        $director = $this->employee([
            'employment_type' => Employee::TYPE_DIRECTOR,
            'payment_basis' => Employee::BASIS_SALARY,
            'annual_salary' => 60000,
        ]);

        $run = $this->payroll->createRun([
            'frequency' => 'monthly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'payment_date' => '2026-09-30',
        ]);
        $this->payroll->addPayslip($run, $director);

        $payslip = $run->payslips()->first();
        $this->assertEquals(5000.0, (float) $payslip->gross);
        $this->assertEquals(600.0, (float) $payslip->super);
        $this->assertGreaterThan(0, (float) $payslip->payg_withheld);
        $this->assertEquals(round(5000 - (float) $payslip->payg_withheld, 2), (float) $payslip->net_pay);
    }

    public function test_super_guarantee_rate_follows_the_pay_date(): void
    {
        $jane = $this->employee();

        $this->assertSame(0.12, $this->payroll->sgRate(Carbon::parse('2026-09-15')));
        $this->assertSame(0.115, $this->payroll->sgRate(Carbon::parse('2026-06-30')));
    }

    public function test_processing_a_run_posts_the_three_journals(): void
    {
        $jane = $this->employee(); // 76h × $50 = $3,800 gross, PAYG $856, super $456
        $run = $this->runWithPayslip($jane);

        $this->payroll->process($run);

        $payslip = $run->payslips()->first();
        $this->assertEquals(3800.0, (float) $payslip->gross);
        $this->assertEquals(852.0, (float) $payslip->payg_withheld);
        $this->assertEquals(456.0, (float) $payslip->super);
        $this->assertEquals(2948.0, (float) $payslip->net_pay);

        // Dr expenses, Cr liabilities and the bank; the wages-payable
        // accrual nets to zero once the net is paid.
        $this->assertEquals(3800.0, $this->balance(5100));
        $this->assertEquals(456.0, $this->balance(5150));
        $this->assertEquals(-852.0, $this->balance(2210));
        $this->assertEquals(-456.0, $this->balance(2220));
        $this->assertEquals(0.0, $this->balance(2235));
        $this->assertEquals(-2948.0, $this->balance(320));

        $this->assertTrue($run->refresh()->isProcessed());
        $this->assertNotNull($run->ifrs_transaction_id);
    }

    public function test_reversing_a_run_restores_the_balances(): void
    {
        $jane = $this->employee();
        $run = $this->runWithPayslip($jane);
        $this->payroll->process($run);

        $this->payroll->reverse($run);

        $this->assertFalse($run->refresh()->isProcessed());
        $this->assertEquals(0.0, $this->balance(5100));
        $this->assertEquals(0.0, $this->balance(5150));
        $this->assertEquals(0.0, $this->balance(2210));
        $this->assertEquals(0.0, $this->balance(2220));
        $this->assertEquals(0.0, $this->balance(320));
    }

    public function test_a_locked_payment_date_refuses_to_process(): void
    {
        $jane = $this->employee();
        $run = $this->runWithPayslip($jane);

        FiscalPeriod::create([
            'name' => 'Locked month',
            'year' => 2026,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => Carbon::parse('2026-09-01')->startOfMonth(),
            'end_date' => Carbon::parse('2026-09-30')->endOfMonth(),
            'is_locked' => true,
            'locked_at' => now(),
        ]);

        try {
            $this->payroll->process($run);
            $this->fail('Processing into a locked period should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('locked period', $e->getMessage());
        }

        $this->assertFalse($run->refresh()->isProcessed());
        $this->assertEquals(0.0, $this->balance(5100));
    }

    public function test_payslips_snapshot_and_display_the_closely_linked_and_psi_flags(): void
    {
        $spouse = $this->employee([
            'name' => 'Sam Associate',
            'is_closely_linked' => true,
            'is_personal_services' => true,
        ]);
        $run = $this->runWithPayslip($spouse);

        $this->actingAs($this->admin())
            ->get(route('payroll.runs.show', $run))
            ->assertOk()
            ->assertSee('Sam Associate')
            ->assertSee('closely linked')
            ->assertSee('PSI');
    }

    public function test_the_payroll_screens_are_gated(): void
    {
        $staff = tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('staff');

        $this->actingAs($staff)->get('/payroll')->assertForbidden();
        $this->actingAs($this->admin())->get('/payroll')->assertOk()->assertSee('New pay run');
    }
}
