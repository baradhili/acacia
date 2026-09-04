<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Service;
// use App\Models\Skill;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;

class ServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $services = Service::paginate(max(1, min((int) $request->input('perPage', 10), 100)));

        // Decode the JSON data for required_skills and extract the values
        foreach ($services as $service) {
            $decodedSkills = json_decode($service->required_skills, true) ?? [];
            $service->required_skills = array_column($decodedSkills, 'value');
        }

        return view('service.index', compact('services'))
            ->with('i', ($request->input('page', 1) - 1) * $services->perPage());
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $service = new Service;

        return view('service.create', compact('service'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ServiceRequest $request): RedirectResponse
    {

        // Service::create($request->validated());
        $validatedData = $request->validate([
            'service_name' => 'required|string|max:255',
            'description' => 'required|string',
            'required_skills' => 'nullable|string',
            'hours_cost' => 'nullable|numeric|min:0',
        ]);

        // Convert the skills string to an array
        $skillsArray = explode(',', $validatedData['required_skills']);
        $skillsArray = array_map('trim', $skillsArray); // Trim whitespace from each skill
        $skills = json_encode($skillsArray);

        // Convert the description field so that all new lines are converted to correct markdown
        $description = str_replace(["\r\n", "\r", "\n"], "  \n", $validatedData['description']);

        // Create the service catalogue entry
        $serviceCatalogue = Service::create([
            'service_name' => $validatedData['service_name'],
            'description' => $description,
            'required_skills' => $skills,
            'hours_cost' => $validatedData['hours_cost'],
        ]);

        return Redirect::route('services.index')
            ->with('success', 'Service created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show($id): View
    {
        $service = Service::find($id);

        return view('service.show', compact('service'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id): View
    {

        $service = Service::find($id);
        // Decode the JSON data for required_skills
        $service->required_skills = json_decode($service->required_skills, true) ?? [];
        Log::info('skills: '.json_encode($service->required_skills));
        // Fetch all skill names for the whitelist
        $skills = Skill::pluck('skill_name')->toArray();

        return view('service.edit', compact('service', 'skills'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        // Validate the request
        $validatedData = $request->validated();

        // Decode the required_skills JSON string to an array
        $requiredSkills = json_decode($request->required_skills, true) ?? [];

        // Convert the description field so that all new lines are converted to correct markdown
        $description = str_replace(["\r\n", "\r", "\n"], "  \n", $validatedData['description']);

        // Update the service catalogue entry
        $service->update([
            'service_name' => $validatedData['service_name'],
            'description' => $description,
            'required_skills' => $requiredSkills,
            'hours_cost' => $validatedData['hours_cost'],
        ]);

        return Redirect::route('services.index')
            ->with('success', 'Service updated successfully');
    }

    public function destroy($id): RedirectResponse
    {
        Service::find($id)->delete();

        return Redirect::route('services.index')
            ->with('success', 'Service deleted successfully');
    }

    public function downloadDocx(int $id)
    {
        $service = Service::findOrFail($id);

        // Convert Markdown to HTML (includes GFM tables)
        $html = Markdown::convertToHtml($service->description);

        // Allow table tags + basic formatting
        $allowedTags = '<p><h1><h2><h3><h4><h5><h6><strong><b><em><i><u><ul><ol><li><br><blockquote>'
            .'<table><thead><tbody><tr><th><td>';

        $cleanHtml = strip_tags($html, $allowedTags);

        // Optional: Normalize whitespace but preserve table cell content
        // Remove excessive spaces between tags, but not inside text
        $cleanHtml = preg_replace('/>\s+</', '><', $cleanHtml); // no space between tags
        $cleanHtml = trim($cleanHtml);

        if ($cleanHtml === '') {
            $cleanHtml = '<p>No description available.</p>';
        }

        // Configure PHPWord
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection([
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 1080,
            'marginRight' => 1080,
        ]);

        // Add HTML (now with safe table support)
        Html::addHtml($section, $cleanHtml, false, false);

        $fileName = 'service_'.$service->id.'_'.Str::slug($service->service_name).'.docx';

        return response()->streamDownload(function () use ($phpWord) {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }
}
