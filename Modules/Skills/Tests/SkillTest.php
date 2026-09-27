<?php

namespace Modules\Skills\Tests;

use App\Models\Service;
use App\Models\User;
use App\Services\IfrsPosting;
use Database\Seeders\IFRSSeeder;
use Illuminate\Support\Facades\Auth;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Services\PayrollService;
use Modules\Resumes\Models\Resume;
use Modules\Skills\Models\EmployeeSkill;
use Modules\Skills\Models\ServiceSkill;
use Modules\Skills\Models\Skill;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The skill register: library CRUD, and the two link matrices —
 * payroll payees hold skills at a proficiency level, services
 * require skills outright. Browsing is open to every signed-in
 * user; changing the library or the links is admin/accountant work,
 * matching the master data each side hangs off.
 */
class SkillTest extends TestCase
{
    public function test_admins_can_create_edit_and_delete_skills(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('skills.store'), [
                'name' => 'BAS Preparation',
                'category' => 'Tax',
                'description' => 'Business activity statements end to end.',
            ])->assertRedirect(route('skills.show', Skill::first()));

        $skill = Skill::firstWhere('name', 'BAS Preparation');
        $this->assertNotNull($skill);
        $this->assertSame('Tax', $skill->category);

        // updates come from a spoofed-PUT form post, not ->put()
        $this->actingAs($admin)
            ->post(route('skills.update', $skill), [
                '_method' => 'PUT',
                'name' => 'BAS & IAS Preparation',
                'category' => 'Tax',
            ])->assertRedirect(route('skills.show', $skill));

        $this->assertSame(1, Skill::count());
        $this->assertSame('BAS & IAS Preparation', $skill->fresh()->name);

        $this->actingAs($admin)
            ->delete(route('skills.destroy', $skill))
            ->assertRedirect(route('skills.index'));
        $this->assertSame(0, Skill::count());
    }

    public function test_skill_names_must_be_unique(): void
    {
        $existing = $this->skill(['name' => 'BAS Preparation']);

        $this->actingAs($this->admin())
            ->post(route('skills.store'), ['name' => 'BAS Preparation'])
            ->assertSessionHasErrors('name');

        // but the skill keeping its own name on edit is fine
        $this->actingAs($this->admin())
            ->post(route('skills.update', $existing), [
                '_method' => 'PUT',
                'name' => 'BAS Preparation',
                'description' => 'Refreshed wording.',
            ])->assertRedirect(route('skills.show', $existing));
    }

    public function test_staff_can_browse_the_library_but_not_manage_it(): void
    {
        $skill = $this->skill();

        $this->actingAs($this->staff())
            ->get(route('skills.index'))->assertOk()->assertSee($skill->name);
        $this->actingAs($this->staff())
            ->get(route('skills.show', $skill))->assertOk();

        $this->actingAs($this->staff())->get(route('skills.create'))->assertForbidden();
        $this->actingAs($this->staff())
            ->post(route('skills.store'), ['name' => 'Sneaky'])->assertForbidden();
        $this->actingAs($this->staff())
            ->get(route('skills.edit', $skill))->assertForbidden();
        $this->actingAs($this->staff())
            ->post(route('skills.update', $skill), ['_method' => 'PUT', 'name' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->staff())
            ->delete(route('skills.destroy', $skill))->assertForbidden();
        $this->assertSame(1, Skill::count());
    }

    public function test_guests_are_asked_to_sign_in(): void
    {
        Auth::logout(); // the seeder leaves its admin signed in

        $this->get(route('skills.index'))->assertRedirect(route('login'));
        $this->get(route('skills.employees.index'))->assertRedirect(route('login'));
        $this->get(route('skills.services.index'))->assertRedirect(route('login'));
    }

    public function test_every_page_renders(): void
    {
        $admin = $this->admin();
        $skill = $this->skill(['description' => 'Business activity statements.']);
        $employee = $this->employee();
        $service = Service::create(['name' => 'BAS Agent Service', 'hourly_rate' => 150]);

        EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => $skill->id, 'proficiency' => 'expert']);

        $this->actingAs($admin)->get(route('skills.index'))->assertOk();
        $this->actingAs($admin)->get(route('skills.index', ['q' => 'bas', 'category' => 'Tax']))->assertOk()->assertSee('BAS Preparation');
        $this->actingAs($admin)->get(route('skills.create'))->assertOk();
        $this->actingAs($admin)->get(route('skills.edit', $skill))->assertOk();
        $this->actingAs($admin)->get(route('skills.show', $skill))->assertOk()->assertSee($employee->name)->assertSee('Expert');
        $this->actingAs($admin)->get(route('skills.employees.index'))->assertOk()->assertSee($employee->name);
        $this->actingAs($admin)->get(route('skills.employees.show', $employee))->assertOk();
        $this->actingAs($admin)->get(route('skills.services.index'))->assertOk()->assertSee('BAS Agent Service');
        $this->actingAs($admin)->get(route('skills.services.show', $service))->assertOk();
    }

    public function test_library_search_matches_percent_and_underscore_literally(): void
    {
        $this->skill(['name' => '100% Complete', 'description' => 'full completion']);
        $this->skill(['name' => '100 Percent Complete']);
        $this->skill(['name' => 'Tax_Planning']);
        $this->skill(['name' => 'Tax Planning']);

        // '%' and '_' are user text here, not wildcards
        $this->actingAs($this->admin())
            ->get(route('skills.index', ['q' => '100%']))
            ->assertOk()
            ->assertSee('100% Complete')
            ->assertDontSee('100 Percent Complete');

        $this->actingAs($this->admin())
            ->get(route('skills.index', ['q' => 'Tax_']))
            ->assertOk()
            ->assertSee('Tax_Planning')
            ->assertDontSee('Tax Planning');
    }

    public function test_library_search_folds_case_beyond_ascii(): void
    {
        $this->skill(['name' => 'École de Danse']);
        $this->skill(['name' => 'BAS Preparation']);

        // the reviewer's case: SQL lower() leaves É uppercased on
        // SQLite, so an instr() search cannot fold it — mb_stripos
        // folds both sides and finds it
        $this->actingAs($this->admin())
            ->get(route('skills.index', ['q' => 'école de danse']))
            ->assertOk()
            ->assertSee('École de Danse')
            ->assertDontSee('BAS Preparation');

        $this->actingAs($this->admin())
            ->get(route('skills.index', ['q' => 'ÉCOLE']))
            ->assertOk()
            ->assertSee('École de Danse');
    }

    public function test_the_payroll_employee_view_shows_skills_and_resumes(): void
    {
        // super_rate is stored as a fraction (0.115 = 11.5%) — the
        // view must render the percentage, not "0.115%"
        $employee = $this->employee(['super_rate' => 0.115]);
        $skill = $this->skill(['name' => 'BAS Preparation']);
        EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => $skill->id, 'proficiency' => 'advanced']);
        $resume = Resume::create([
            'employee_id' => $employee->id,
            'entity_id' => IfrsPosting::resolveEntity()->id,
            'uploaded_by' => User::factory()->create(['entity_id' => IfrsPosting::resolveEntity()->id])->id,
            'original_filename' => 'sample-resume.json',
            'parsed_data' => ['basics' => ['name' => 'Richard Hendriks CV']],
            'uploaded_at' => now(),
        ]);

        // the payee view: payroll master data, the skills panel
        // below it, and the payee's resumes
        $this->actingAs($this->admin())
            ->get(route('payroll.employees.show', $employee))
            ->assertOk()
            ->assertSee('Payroll details')
            ->assertSee('11.5%')
            ->assertDontSee('0.115%')
            ->assertSee('Skills held')
            ->assertSee('BAS Preparation')
            ->assertSee('Advanced')
            ->assertSee('Resumes')
            ->assertSee('Richard Hendriks CV')
            ->assertSee(route('resumes.show', $resume));

        // the index row links to the view (and the skills matrix)
        $this->actingAs($this->admin())
            ->get(route('payroll.employees.index'))
            ->assertOk()
            ->assertSee(route('payroll.employees.show', $employee))
            ->assertSee(route('skills.employees.show', $employee));

        // payroll master data stays admin/accountant territory
        $this->actingAs($this->staff())
            ->get(route('payroll.employees.show', $employee))
            ->assertForbidden();
    }

    public function test_the_payroll_employee_view_shows_the_super_guarantee_default(): void
    {
        // super_rate null: payroll falls back to the SG rate in
        // force — the view shows that default, not a bare dash
        $employee = $this->employee();
        $expected = rtrim(rtrim(number_format(app(PayrollService::class)->sgRate(now()) * 100, 2), '0'), '.').'%';

        $this->actingAs($this->admin())
            ->get(route('payroll.employees.show', $employee))
            ->assertOk()
            ->assertSee($expected)
            ->assertSee('super guarantee default');
    }

    public function test_fixed_fee_services_do_not_get_an_hourly_suffix(): void
    {
        $hourly = Service::create(['name' => 'Payroll Service', 'hourly_rate' => 150]);
        $fixed = Service::create(['name' => 'Setup Fee']);
        $skill = $this->skill();

        $this->actingAs($this->admin())
            ->get(route('skills.services.show', $hourly))
            ->assertOk()
            ->assertSee('$150/hr');

        $this->actingAs($this->admin())
            ->get(route('skills.services.show', $fixed))
            ->assertOk()
            ->assertDontSee('/hr');

        // the per-skill page lists linked services the same way
        ServiceSkill::create(['service_id' => $fixed->id, 'skill_id' => $skill->id]);

        $this->actingAs($this->admin())
            ->get(route('skills.show', $skill))
            ->assertOk()
            ->assertSee('Setup Fee')
            ->assertDontSee('/hr');
    }

    public function test_admins_set_an_employees_skills_with_proficiency(): void
    {
        $employee = $this->employee();
        [$bas, $gst, $payroll] = [$this->skill(['name' => 'BAS']), $this->skill(['name' => 'GST']), $this->skill(['name' => 'Payroll'])];

        // both skills at once, one at expert
        $this->actingAs($this->admin())
            ->post(route('skills.employees.update', $employee), [
                'skills' => [$bas->id => '1', $payroll->id => '1'],
                'proficiency' => [$payroll->id => 'expert'],
            ])->assertRedirect(route('skills.employees.show', $employee));

        $this->assertSame(2, EmployeeSkill::where('employee_id', $employee->id)->count());
        $this->assertSame('beginner', EmployeeSkill::where(['employee_id' => $employee->id, 'skill_id' => $bas->id])->value('proficiency'));
        $this->assertSame('expert', EmployeeSkill::where(['employee_id' => $employee->id, 'skill_id' => $payroll->id])->value('proficiency'));

        // re-posting replaces the set: one dropped, one kept, one added
        $this->actingAs($this->admin())
            ->post(route('skills.employees.update', $employee), [
                'skills' => [$bas->id => '1', $gst->id => '1'],
                'proficiency' => [$bas->id => 'advanced', $gst->id => 'intermediate'],
            ])->assertRedirect(route('skills.employees.show', $employee));

        $this->assertSame(2, EmployeeSkill::where('employee_id', $employee->id)->count());
        $this->assertNull(EmployeeSkill::where(['employee_id' => $employee->id, 'skill_id' => $payroll->id])->first());
        $this->assertSame('advanced', EmployeeSkill::where(['employee_id' => $employee->id, 'skill_id' => $bas->id])->value('proficiency'));
        $this->assertSame('intermediate', EmployeeSkill::where(['employee_id' => $employee->id, 'skill_id' => $gst->id])->value('proficiency'));

        // the matrix renders the link for staff too
        $this->actingAs($this->staff())
            ->get(route('skills.employees.show', $employee))
            ->assertOk()
            ->assertSee('BAS')
            ->assertSee('Advanced');
    }

    public function test_proficiency_must_be_a_known_level(): void
    {
        $employee = $this->employee();
        $skill = $this->skill();

        $this->actingAs($this->admin())
            ->post(route('skills.employees.update', $employee), [
                'skills' => [$skill->id => '1'],
                'proficiency' => [$skill->id => 'wizard'],
            ])->assertSessionHasErrors('proficiency.'.$skill->id);

        $this->assertSame(0, EmployeeSkill::count());
    }

    public function test_admins_set_a_services_required_skills(): void
    {
        $service = Service::create(['name' => 'BAS Agent Service', 'description' => 'Lodgement under the agent portal']);
        [$bas, $gst] = [$this->skill(['name' => 'BAS']), $this->skill(['name' => 'GST'])];

        $this->actingAs($this->admin())
            ->post(route('skills.services.update', $service), ['skills' => [$bas->id => '1', $gst->id => '1']])
            ->assertRedirect(route('skills.services.show', $service));
        $this->assertSame(2, ServiceSkill::where('service_id', $service->id)->count());

        // re-posting replaces the set
        $this->actingAs($this->admin())
            ->post(route('skills.services.update', $service), ['skills' => [$gst->id => '1']])
            ->assertRedirect(route('skills.services.show', $service));
        $this->assertSame(1, ServiceSkill::where('service_id', $service->id)->count());
        $this->assertNotNull(ServiceSkill::where(['service_id' => $service->id, 'skill_id' => $gst->id])->first());

        $this->actingAs($this->staff())
            ->get(route('skills.services.show', $service))
            ->assertOk()
            ->assertSee('GST');
    }

    public function test_staff_cannot_sync_either_matrix(): void
    {
        $employee = $this->employee();
        $service = Service::create(['name' => 'BAS Agent Service']);
        $skill = $this->skill();

        $this->actingAs($this->staff())
            ->post(route('skills.employees.update', $employee), ['skills' => [$skill->id => '1']])
            ->assertForbidden();
        $this->actingAs($this->staff())
            ->post(route('skills.services.update', $service), ['skills' => [$skill->id => '1']])
            ->assertForbidden();

        $this->assertSame(0, EmployeeSkill::count());
        $this->assertSame(0, ServiceSkill::count());
    }

    public function test_deleting_a_skill_cascades_to_both_links(): void
    {
        $employee = $this->employee();
        $service = Service::create(['name' => 'BAS Agent Service']);
        $skill = $this->skill();

        EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => $skill->id, 'proficiency' => 'advanced']);
        ServiceSkill::create(['service_id' => $service->id, 'skill_id' => $skill->id]);

        $skill->delete();

        $this->assertSame(0, EmployeeSkill::count());
        $this->assertSame(0, ServiceSkill::count());
    }

    public function test_deleting_an_employee_or_service_cascades_to_their_links(): void
    {
        $employee = $this->employee();
        $service = Service::create(['name' => 'BAS Agent Service']);
        $skill = $this->skill();

        EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => $skill->id]);
        ServiceSkill::create(['service_id' => $service->id, 'skill_id' => $skill->id]);

        $employee->delete();
        $service->delete();

        $this->assertSame(0, EmployeeSkill::count());
        $this->assertSame(0, ServiceSkill::count());
        $this->assertSame(1, Skill::count()); // the skill itself survives
    }

    protected function skill(array $attributes = []): Skill
    {
        return Skill::create(array_merge([
            'name' => 'BAS Preparation',
            'category' => 'Tax',
        ], $attributes));
    }

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'entity_id' => IfrsPosting::resolveEntity()->id,
            'name' => 'Richard Hendriks',
            'email' => 'richard@piedpiper.example',
            'employment_type' => Employee::TYPE_EMPLOYEE,
            'status' => Employee::STATUS_ACTIVE,
        ], $attributes));
    }

    protected function staff(): User
    {
        return tap(User::factory()->create(['entity_id' => IfrsPosting::resolveEntity()->id]))->assignRole('staff');
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => IfrsPosting::resolveEntity()->id]))->assignRole('admin');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);
    }
}
