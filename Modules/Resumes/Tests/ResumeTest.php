<?php

namespace Modules\Resumes\Tests;

use App\Models\User;
use App\Services\IfrsPosting;
use Database\Seeders\IFRSSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Modules\Payroll\Models\Employee;
use Modules\Resumes\Models\Resume;
use Modules\Resumes\Services\ResumeDocxService;
use Modules\Resumes\Services\ResumeFilterService;
use Modules\Resumes\Services\ResumePdfService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Employee resumes on the modified JSON Resume schema: upload
 * validation (schema + business rules, basics filled from the payee
 * record), keyword tailoring (any/all match, project cross-references,
 * emptied-section cleanup), and the four exports — filtered JSON, the
 * LaTeX source, a real LuaLaTeX-compiled PDF and a PHPWord DOCX.
 * Viewing and exporting are open to every signed-in user; managing
 * (upload/delete) is limited to admins and the payee's linked user.
 */
class ResumeTest extends TestCase
{
    public function test_staff_can_upload_browse_and_delete_their_own_resume(): void
    {
        $staff = $this->staff();
        $employee = $this->employee(['user_id' => $staff->id]);

        $response = $this->actingAs($staff)
            ->post(route('resumes.store'), [
                'employee_id' => $employee->id,
                'resume_file' => $this->resumeFile(),
            ]);

        $resume = Resume::query()->first();
        $response->assertRedirect(route('resumes.show', $resume));
        $this->assertNotNull($resume);
        $this->assertSame($employee->id, $resume->employee_id);
        $this->assertSame($staff->id, $resume->uploaded_by);
        $this->assertSame('Richard Hendriks', $resume->parsed_data['basics']['name']);

        $this->actingAs($staff)->get(route('resumes.index'))->assertOk()->assertSee('Richard Hendriks');
        $this->actingAs($staff)->get(route('resumes.show', $resume))->assertOk()->assertSee('Pied Piper');

        $this->actingAs($staff)->delete(route('resumes.destroy', $resume))->assertRedirect(route('resumes.index'));
        $this->assertSame(0, Resume::count());
    }

    public function test_only_admins_or_the_owning_employee_can_manage_resumes(): void
    {
        $resume = $this->resume(); // an unlinked payee's resume

        // plain staff can still view and export…
        $this->actingAs($this->staff())
            ->get(route('resumes.index'))->assertOk()->assertSee('Richard Hendriks');
        $this->actingAs($this->staff())
            ->get(route('resumes.show', $resume))->assertOk();

        // …but not upload for a payee that isn't theirs, nor delete
        $this->actingAs($this->staff())
            ->post(route('resumes.store'), [
                'employee_id' => $resume->employee_id,
                'resume_file' => $this->resumeFile(),
            ])->assertForbidden();
        $this->actingAs($this->staff())
            ->delete(route('resumes.destroy', $resume))->assertForbidden();
        $this->assertSame(1, Resume::count());

        // the owning employee — the payee's linked user — can delete it
        $owner = $this->staff();
        $resume->employee->forceFill(['user_id' => $owner->id])->save();
        $this->actingAs($owner)
            ->delete(route('resumes.destroy', $resume))->assertRedirect(route('resumes.index'));

        // and admins can manage any payee's resume
        $adminResume = $this->resume();
        $this->actingAs($this->admin())
            ->delete(route('resumes.destroy', $adminResume))->assertRedirect(route('resumes.index'));
        $this->actingAs($this->admin())
            ->post(route('resumes.store'), [
                'employee_id' => $adminResume->employee_id,
                'resume_file' => $this->resumeFile(),
            ])->assertRedirect();
    }

    public function test_uploads_must_match_the_schema(): void
    {
        $employee = $this->employee();

        // work must be an array of entries, not a string
        $tampered = $this->resumeData();
        $tampered['work'] = 'CEO at Pied Piper';

        $this->actingAs($this->admin())
            ->post(route('resumes.store'), [
                'employee_id' => $employee->id,
                'resume_file' => $this->resumeFile($tampered),
            ])
            ->assertInvalid(['resume_file']);

        $this->assertStringContainsString('[work]', session('errors')->first('resume_file'));

        // malformed JSON is rejected before the schema runs
        $this->actingAs($this->admin())
            ->post(route('resumes.store'), [
                'employee_id' => $employee->id,
                'resume_file' => UploadedFile::fake()->createWithContent('resume.json', '{not json'),
            ])
            ->assertInvalid(['resume_file']);

        $this->assertSame(0, Resume::count());
    }

    public function test_missing_basics_are_filled_from_the_employee_record(): void
    {
        $employee = $this->employee(['name' => 'Jane Worker', 'email' => 'jane@acacia.test']);
        $data = $this->resumeData();
        unset($data['basics']['name'], $data['basics']['email']);

        $this->actingAs($this->admin())
            ->post(route('resumes.store'), [
                'employee_id' => $employee->id,
                'resume_file' => $this->resumeFile($data),
            ])
            ->assertRedirect();

        $resume = Resume::query()->firstOrFail();
        $this->assertSame('Jane Worker', $resume->parsed_data['basics']['name']);
        $this->assertSame('jane@acacia.test', $resume->parsed_data['basics']['email']);
    }

    public function test_keyword_filtering_tails_the_resume(): void
    {
        $data = $this->resumeData();
        $data['work'][0]['crossReferencedProjects'] = ['Payroll Migration'];
        $data['work'][] = [
            'employer' => 'Acacia Bookkeeping',
            'position' => 'Payroll officer',
            'startDate' => '2024-01-01',
            'highlights' => ['Ran fortnightly payroll for 40 payees'],
        ];
        $data['projects'][] = [
            'id' => 'Payroll Migration',
            'name' => 'Payroll Migration',
            'description' => 'Moved payroll onto the ERP',
        ];
        $data['certificates'] = [['name' => 'Scuba Diving', 'issuer' => 'PADI']];

        // match-any: 'payroll' keeps the payroll job, the migration
        // project, and the compression job only via its cross-reference
        $filtered = ResumeFilterService::filter($data, 'payroll');
        $this->assertCount(2, $filtered['work']);
        $this->assertSame('Payroll officer', $filtered['work'][1]['position']);
        $this->assertSame('Payroll Migration', $filtered['projects'][0]['name']);

        // emptied sections disappear entirely — including certificates,
        // which the upstream repo's cleanup list missed
        $this->assertArrayNotHasKey('skills', $filtered);
        $this->assertArrayNotHasKey('certificates', $filtered);
        $this->assertArrayNotHasKey('languages', $filtered);

        // a work item kept by keyword keeps cross-references to
        // projects that survived alongside it
        $this->assertSame(['Payroll Migration'], $filtered['work'][0]['crossReferencedProjects']);

        $metadata = ResumeFilterService::getFilterMetadata($data, $filtered);
        $this->assertSame(2, $metadata['removed_counts']['skills']);

        // match-all requires one field to contain every keyword
        $both = ResumeFilterService::filter($data, 'pied piper, compression', true);
        $this->assertCount(1, $both['work']);
        $this->assertSame('CEO/President', $both['work'][0]['position']);
        // kept by its own summary — the filtered-out project reference
        // is dropped from it
        $this->assertSame([], $both['work'][0]['crossReferencedProjects']);

        $none = ResumeFilterService::filter($data, 'compression, ferrets', true);
        $this->assertArrayNotHasKey('work', $none);
    }

    public function test_latex_source_renders_and_escapes(): void
    {
        $this->assertSame('50\\% \\& \\$team', ResumePdfService::escapeLatex('50% & $team'));
        $this->assertSame('a\\_b\\textasciicircum{}c\\textasciitilde{}d', ResumePdfService::escapeLatex('a_b^c~d'));
        $this->assertSame('\\textbackslash{}n', ResumePdfService::escapeLatex('\\n'));

        $tex = ResumePdfService::renderLatexSource($this->resumeData());

        $this->assertStringContainsString('\documentclass', $tex);
        $this->assertStringContainsString('Richard Hendriks', $tex);
        $this->assertStringContainsString('Pied Piper is a multi-platform', $tex);
    }

    public function test_pdf_compiles_when_latex_is_available(): void
    {
        if (! config('resumes.latex.enabled') || ! is_executable(config('resumes.latex.bin'))) {
            $this->markTestSkipped('LuaLaTeX is not available on this machine.');
        }

        $pdf = ResumePdfService::compile($this->resumeData());

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_docx_export_is_a_word_document(): void
    {
        $docx = ResumeDocxService::render($this->resumeData());

        $this->assertStringStartsWith('PK', $docx);

        $path = tempnam(sys_get_temp_dir(), 'resumes-test-docx-');
        file_put_contents($path, $docx);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('Richard Hendriks', $document);
        $this->assertStringContainsString('Pied Piper', $document);
    }

    public function test_filtered_json_download_carries_filter_metadata(): void
    {
        $resume = $this->resume();

        $response = $this->actingAs($this->staff())
            ->get(route('resumes.json', ['resume' => $resume, 'keywords' => 'compression, payroll']));

        $response->assertOk();
        $export = json_decode($response->streamedContent(), true);

        $this->assertSame('compression, payroll', $export['$filter']['keywords']);
        $this->assertFalse($export['$filter']['matchAll']);
        $this->assertArrayHasKey('$exportedAt', $export);
        $this->assertArrayHasKey('$exportedBy', $export);
        // filtered down to the compression-relevant entries
        $this->assertArrayNotHasKey('languages', $export);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        // IFRSSeeder leaves its admin user authenticated (it calls
        // Auth::login "for IFRS operations"), so log out first
        Auth::logout();

        $this->get(route('resumes.index'))->assertRedirect(route('login'));
    }

    public function test_deleting_an_employee_cascades_to_their_resumes(): void
    {
        $employee = $this->employee();
        $this->resume($employee);

        $employee->delete();

        $this->assertSame(0, Resume::count());
    }

    protected function employee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'entity_id' => IfrsPosting::resolveEntity()->id,
            'name' => 'Jane Worker',
            'employment_type' => Employee::TYPE_EMPLOYEE,
            'payment_basis' => Employee::BASIS_HOURLY,
            'hourly_rate' => 50,
            'status' => 'active',
        ], $overrides));
    }

    protected function staff(): User
    {
        return tap(User::factory()->create(['entity_id' => IfrsPosting::resolveEntity()->id]))->assignRole('staff');
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => IfrsPosting::resolveEntity()->id]))->assignRole('admin');
    }

    protected function resume(?Employee $employee = null): Resume
    {
        return Resume::create([
            'employee_id' => ($employee ?? $this->employee())->id,
            'entity_id' => IfrsPosting::resolveEntity()->id,
            'uploaded_by' => User::factory()->create(['entity_id' => IfrsPosting::resolveEntity()->id])->id,
            'original_filename' => 'sample-resume.json',
            'parsed_data' => $this->resumeData(),
            'uploaded_at' => now(),
        ]);
    }

    protected function resumeData(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/sample-resume.json'), true);
    }

    protected function resumeFile(?array $data = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'resume.json',
            json_encode($data ?? $this->resumeData(), JSON_UNESCAPED_UNICODE)
        );
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
