{{-- Standalone by design: no app layout, Vite assets or runtime state —
     this page renders when the app is broken. Hardening headers still
     apply (global middleware). Strings via lang/errors.php. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('errors.server_error_title') }}</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #1e293b; color: #f1f5f9;
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        main { text-align: center; padding: 2rem; max-width: 32rem; }
        .code {
            font-size: 4.5rem; font-weight: 700; line-height: 1;
            color: #94a3b8; letter-spacing: -0.05em;
        }
        h1 { font-size: 1.5rem; margin: 0.75rem 0 0.5rem; }
        p { color: #cbd5e1; line-height: 1.6; margin: 0 0 1.75rem; }
        a {
            display: inline-block; padding: 0.6rem 1.25rem; border-radius: 0.5rem;
            background: #0f172a; color: #f8fafc; text-decoration: none; font-weight: 600;
        }
        a:hover { background: #334155; }
    </style>
</head>
<body>
    <main>
        <p class="code">{{ __('errors.server_error_code') }}</p>
        <h1>{{ __('errors.server_error_title') }}</h1>
        <p>{{ __('errors.server_error_body') }}</p>
        <a href="{{ url('/') }}">{{ __('errors.back_home') }}</a>
    </main>
</body>
</html>
