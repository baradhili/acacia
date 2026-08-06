<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    /**
     * Display a listing of documents for a specific model.
     */
    public function index(Request $request)
    {
        $type = $request->get('type');
        $id = $request->get('id');

        if (!$type || !$id) {
            return response()->json(['error' => 'Type and ID are required'], 400);
        }

        $documents = Document::where('documentable_type', $type)
            ->where('documentable_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }

    /**
     * Store a newly uploaded document.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'documentable_type' => 'required|string',
            'documentable_id' => 'required|integer',
            'file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,gif,doc,docx,xls,xlsx,txt,zip,rar',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Convert short class name to full namespace if needed
        $documentableType = $request->documentable_type;
        if (!str_contains($documentableType, '\\')) {
            $documentableType = 'App\\Models\\' . $documentableType;
        }

        $file = $request->file('file');
        $path = $file->store(
            'uploads/' . now()->format('Y/m'),
            'public'
        );

        $document = Document::create([
            'documentable_type' => $documentableType,
            'documentable_id' => $request->documentable_id,
            'name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        return response()->json($document, 201);
    }

    /**
     * Display the specified document.
     */
    public function show(Document $document)
    {
        return response()->json($document);
    }

    /**
     * Download the specified document.
     */
    public function download(Document $document)
    {
        if (!Storage::disk('public')->exists($document->file_path)) {
            abort(404, 'File not found');
        }

        return Storage::disk('public')->download(
            $document->file_path,
            $document->name
        );
    }

    /**
     * Remove the specified document.
     */
    public function destroy(Document $document)
    {
        // Delete the file from storage
        if (Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted successfully']);
    }

    /**
     * Get documents for a specific model (API endpoint)
     */
    public function forModel(Request $request, string $type, int $id)
    {
        // Convert short class name to full namespace
        $fullType = 'App\\Models\\' . $type;
        
        $documents = Document::where('documentable_type', $fullType)
            ->where('documentable_id', $id)
            ->with('uploadedBy')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }
}
