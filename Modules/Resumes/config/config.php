<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    | Resumes arrive as JSON files on the (modified) JSON Resume v1.0.0
    | schema — a couple of hundred KB at most in practice.
    */

    'upload_max_kb' => env('RESUMES_UPLOAD_MAX_KB', 2048),

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    | The merged schema used to validate uploads. Vendored with the
    | module (regenerate with resumes:fetch-schema); never fetched at
    | runtime.
    */

    'schema' => __DIR__.'/../resources/schemas/json-resume-merged.json',

    /*
    |--------------------------------------------------------------------------
    | LaTeX PDF rendering
    |--------------------------------------------------------------------------
    | The engine must be a LuaLaTeX-compatible binary (fontspec).
    | When disabled (or missing) PDF downloads fall back to an error
    | flash; JSON / LaTeX / DOCX exports keep working.
    */

    'latex' => [
        'enabled' => env('RESUMES_LATEX_ENABLED', true),
        'bin' => env('RESUMES_LATEX_BIN', '/usr/bin/lualatex'),
        'template' => 'resumes::latex.resume',
    ],
];
