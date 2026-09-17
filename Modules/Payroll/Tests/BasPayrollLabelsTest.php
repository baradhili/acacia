<?php

namespace Modules\Payroll\Tests;

use App\Models\User;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayRun;
use Modules\Payroll\Models\Payslip;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The BAS report's W1/W2 labels are the payroll module's contribution
 * to a core report: processed pay runs' gross and withheld by pay
 * day, frozen with the rest when a quarter is lodged. Kept with the
 * module because both sides of the integration move together.
 */
class BasPayrollLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected int $fyEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        $this->fyEnd = ReportingPeriod::year(now(), $this->entity) + 1;
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

    public function test_w1_w2_come_from_processed_pay_runs_and_freeze_with_the_rest(): void
    {
        $employee = Employee::create(['entity_id' => $this->entity->id, 'name' => 'Jane Worker']);

        // A processed fortnight paid inside Q1 (Jul–Sep), plus a draft
        // run that must not count towards the labels.
        $run = PayRun::create([
            'entity_id' => $this->entity->id,
            'period_start' => '2026-09-14',
            'period_end' => '2026-09-27',
            'payment_date' => '2026-09-30',
            'frequency' => 'fortnightly',
            'status' => PayRun::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
        Payslip::create([
            'pay_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross' => 4000,
            'payg_withheld' => 600,
            'net_pay' => 3400,
        ]);
        $draft = PayRun::create([
            'entity_id' => $this->entity->id,
            'period_start' => '2026-09-28',
            'period_end' => '2026-10-11',
            'payment_date' => '2026-09-15',
            'frequency' => 'fortnightly',
            'status' => PayRun::STATUS_DRAFT,
        ]);
        Payslip::create([
            'pay_run_id' => $draft->id,
            'employee_id' => $employee->id,
            'gross' => 5000,
            'payg_withheld' => 800,
            'net_pay' => 4200,
        ]);

        $this->actingAs($this->admin())
            ->get('/reports/bas?fy='.$this->fyEnd)
            ->assertOk()
            ->assertSee('W1 Total salary/wages')
            ->assertSee('$4,000.00')
            ->assertSee('$600.00')
            ->assertDontSee('$5,000.00');

        // W1/W2 freeze with the rest: the lodged quarter keeps its
        // figures even once the run behind them is gone.
        $this->actingAs($this->admin())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 1])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bas_statements', [
            'fy_end' => $this->fyEnd,
            'quarter' => 1,
            'w1' => 4000,
            'w2' => 600,
        ]);

        $run->delete();

        $this->actingAs($this->admin())
            ->get('/reports/bas?fy='.$this->fyEnd)
            ->assertOk()
            ->assertSee('Lodged')
            ->assertSee('$4,000.00')
            ->assertSee('$600.00');
    }
}
