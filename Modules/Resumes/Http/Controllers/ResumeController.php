<?php

namespace Modules\Resumes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\IfrsPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Payroll\Models\Employee;
use Modules\Resumes\Models\Resume;
use Modules\Resumes\Services\JsonResumeValidator;
use Modules\Resumes\Services\ResumeDocxService;
use Modules\Resumes\Services\ResumeFilterService;
use Modules\Resumes\Services\ResumePdfService;
use RuntimeException;

/**
 * Resume uploads against a payroll payee, keyword tailoring and the
 * four exports (JSON, PDF via LaTeX, LaTeX source, DOCX). Open to
 * every signed-in user — resumes are staffing-facing, unlike the
 * payroll data they hang off.
 */
class ResumeController extends Controller
{
    public function index(Request $request)
    {
        $query = Resume::with(['employee', 'uploadedBy'])
            ->where('entity_id', IfrsPosting::resolveEntity()->id)
            ->orderByDesc('uploaded_at');

        if ($request->filled('employee')) {
            $query->where('employee_id', $request->integer('employee'));
        }

        return view('resumes.index', [
            'resumes' => $query->get(),
            'employee' => $request->filled('employee')
                ? Employee::find($request->integer('employee'))
                : null,
        ]);
    }

    public function create()
    {
        return view('resumes.upload', [
            'employees' => Employee::where('entity_id', IfrsPosting::resolveEntity()->id)
                ->orderBy('name')
                ->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request)
    {
        $entityId = IfrsPosting::resolveEntity()->id;

        $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('entity_id', $entityId)],
            // Extension is not enforced: the content must parse as JSON,
            // which any extension of a valid resume file will.
            'resume_file' => ['required', 'file', 'max:'.config('resumes.upload_max_kb')],
        ]);

        $employee = Employee::findOrFail($request->integer('employee_id'));

        $data = $this->decode($request);
        $data = JsonResumeValidator::normalize($data);

        // The payee record already knows these — fill them in when the
        // resume leaves them out rather than rejecting the upload.
        $data['basics'] = $data['basics'] ?? [];
        $data['basics']['name'] = $data['basics']['name'] ?? $employee->name;
        $data['basics']['email'] = $data['basics']['email'] ?? $employee->email;

        $validation = JsonResumeValidator::validate($data);
        if (! $validation['valid']) {
            throw ValidationException::withMessages([
                'resume_file' => 'Resume does not match the required schema. '.implode(' ', array_slice($validation['errors'], 0, 5)),
            ]);
        }

        if (empty($data['work']) && empty($data['education'])) {
            throw ValidationException::withMessages([
                'resume_file' => 'Resume must include at least one work experience or education entry.',
            ]);
        }

        $resume = Resume::create([
            'employee_id' => $employee->id,
            'entity_id' => $entityId,
            'uploaded_by' => $request->user()->id,
            'original_filename' => $request->file('resume_file')->getClientOriginalName(),
            'parsed_data' => $data,
            'json_resume_version' => $data['$schema'] ?? null,
            'uploaded_at' => now(),
        ]);

        return redirect()->route('resumes.show', $resume)->with('success', 'Resume uploaded.');
    }

    public function show(Request $request, Resume $resume)
    {
        [$data, $isFiltered, $keywordsString, $metadata] = $this->filtered($resume, $request);

        return view('resumes.show', [
            'resume' => $resume,
            'data' => $data,
            'isFiltered' => $isFiltered,
            'keywords' => $request->query('keywords'),
            'matchAll' => $request->boolean('match_all'),
            'metadata' => $metadata,
        ]);
    }

    public function destroy(Resume $resume)
    {
        $resume->delete();

        return redirect()->route('resumes.index')->with('success', 'Resume deleted.');
    }

    public function downloadJson(Request $request, Resume $resume)
    {
        [$data, $isFiltered, $keywordsString] = $this->filtered($resume, $request);

        $export = $data;
        $export['$exportedAt'] = now()->toIso8601String();
        $export['$exportedBy'] = $request->user()->email;
        if ($isFiltered) {
            $export['$filter'] = ['keywords' => $keywordsString, 'matchAll' => $request->boolean('match_all')];
        }

        return response()->streamDownload(
            fn () => print (json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            $this->filename($resume, $isFiltered, 'json'),
            ['Content-Type' => 'application/json']
        );
    }

    public function downloadPdf(Request $request, Resume $resume)
    {
        [$data, $isFiltered, $keywordsString] = $this->filtered($resume, $request);

        try {
            $pdf = ResumePdfService::compile($data, $isFiltered, $keywordsString);
        } catch (RuntimeException $e) {
            Log::warning('Resume PDF export failed', ['resume' => $resume->id, 'error' => $e->getMessage()]);

            return redirect()->route('resumes.show', $resume)
                ->with('error', 'PDF export failed: '.$e->getMessage().' The JSON, LaTeX and DOCX exports still work.');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($resume, $isFiltered, 'pdf').'"',
        ]);
    }

    public function downloadLatex(Request $request, Resume $resume)
    {
        [$data, $isFiltered, $keywordsString] = $this->filtered($resume, $request);

        $tex = ResumePdfService::renderLatexSource($data, $isFiltered, $keywordsString);

        return response($tex, 200, [
            'Content-Type' => 'application/x-tex',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($resume, $isFiltered, 'tex').'"',
        ]);
    }

    public function downloadDocx(Request $request, Resume $resume)
    {
        [$data, $isFiltered, $keywordsString] = $this->filtered($resume, $request);

        $docx = ResumeDocxService::render($data, $isFiltered, $keywordsString);

        return response($docx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($resume, $isFiltered, 'docx').'"',
        ]);
    }

    /**
     * Apply the keyword filter to the resume's parsed_data.
     *
     * @return array{0: array, 1: bool, 2: string, 3: ?array}
     *                                                        filtered data, whether filtering happened, the keyword
     *                                                        list as given, per-section removed counts (when filtering)
     */
    protected function filtered(Resume $resume, Request $request): array
    {
        $keywords = $request->query('keywords');
        if (empty($keywords)) {
            return [$resume->parsed_data, false, '', null];
        }

        $matchAll = $request->boolean('match_all');
        $original = $resume->parsed_data;
        $data = ResumeFilterService::filter($original, $keywords, $matchAll);

        return [
            $data,
            true,
            is_array($keywords) ? implode(', ', $keywords) : (string) $keywords,
            ResumeFilterService::getFilterMetadata($original, $data),
        ];
    }

    protected function filename(Resume $resume, bool $isFiltered, string $extension): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '-', strtolower($resume->name ?: 'resume'));

        return trim($base, '-').($isFiltered ? '-filtered' : '').'-'.now()->format('Y-m-d').'.'.$extension;
    }

    protected function decode(Request $request): array
    {
        $contents = file_get_contents($request->file('resume_file')->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['resume_file' => 'The uploaded file could not be read.']);
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::withMessages(['resume_file' => 'The file is not valid JSON: '.$e->getMessage()]);
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages(['resume_file' => 'The resume must be a JSON object.']);
        }

        return $data;
    }
}
