<?php

namespace Modules\Payroll\Tests;

use App\Models\FiscalPeriod;
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
use Modules\Payroll\Observers\UserObserver;
use Modules\Payroll\Services\PayrollService;
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

        foreach (['admin', 'accountant', 'staff', 'client'] as $role) {
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

    public function test_editing_a_payee_loads_their_record(): void
    {
        $jane = $this->employee();

        // If implicit binding skips (controller variable not matching
        // the route parameter), edit renders the blank "Add payee"
        // form instead of Jane's record.
        $this->actingAs($this->admin())
            ->get(route('payroll.employees.edit', $jane))
            ->assertOk()
            ->assertSee('Edit payee')
            ->assertSee($jane->name)
            ->assertSee('value="'.$jane->tfn.'"', false)
            // the form must spoof PUT: a bare POST hits a PUT-only
            // route and dies with MethodNotAllowedHttpException
            ->assertSee('name="_method" value="PUT"', false);

        // the create form must NOT spoof — that would turn the store
        // POST into a PUT and 405 it instead
        $this->actingAs($this->admin())
            ->get(route('payroll.employees.create'))
            ->assertOk()
            ->assertDontSee('name="_method"');

        // submit the way the browser does: a POST body carrying
        // _method=PUT — exercises the spoofing the form relies on
        $admin = $this->admin(); // resolve before counting: each call makes a fresh user/payee
        $countBefore = Employee::count();
        $this->actingAs($admin)
            ->post(route('payroll.employees.update', $jane), [
                '_method' => 'PUT',
                'name' => 'Jane Renamed',
                'employment_type' => Employee::TYPE_EMPLOYEE,
                'payment_basis' => Employee::BASIS_HOURLY,
                'hourly_rate' => 55,
                'status' => 'active',
            ])
            ->assertRedirect(route('payroll.employees.index'));

        // the update must change Jane, not duplicate her (the count is
        // relative — user creation now seeds linked payees)
        $this->assertSame($countBefore, Employee::count());
        $this->assertSame('Jane Renamed', $jane->fresh()->name);
    }

    public function test_every_staff_user_is_seeded_a_linked_payee(): void
    {
        $admin = $this->admin();

        $payee = Employee::where('user_id', $admin->id)->first();
        $this->assertNotNull($payee);
        $this->assertSame($admin->name, $payee->name);
        $this->assertSame($admin->email, $payee->email);
        $this->assertSame($this->entity->id, $payee->entity_id);

        // portal clients are customers, not payees — but the check
        // only bites when the role is held at seeding time (roles are
        // usually assigned after creation; the client role itself is
        // slated for removal)
        $client = tap(User::withoutEvents(
            fn () => User::factory()->create(['entity_id' => $this->entity->id])
        ))->assignRole('client');

        $this->assertNull(UserObserver::ensureEmployeeFor($client));
        $this->assertNull(Employee::where('user_id', $client->id)->first());
    }

    public function test_the_sync_command_backfills_missing_payees(): void
    {
        $admin = $this->admin();
        // a user whose payee predates the observer (or was removed)
        Employee::where('user_id', $admin->id)->delete();
        $this->assertNull(Employee::where('user_id', $admin->id)->first());

        $this->artisan('payroll:sync-users')
            ->expectsOutputToContain("Created payee for {$admin->name}")
            ->assertSuccessful();

        $payee = Employee::where('user_id', $admin->id)->first();
        $this->assertNotNull($payee);
        $this->assertSame($admin->name, $payee->name);

        // idempotent: nothing new on a second run
        $this->artisan('payroll:sync-users')->assertSuccessful();
        $this->assertSame(1, Employee::where('user_id', $admin->id)->count());
    }
}
