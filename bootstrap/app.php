<?php

use App\Http\Middleware\EnsureUserHasEntity;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies to trust for X-Forwarded-* headers (CIDR list or
        // *), empty by default so nothing is trusted locally. Behind
        // a TLS-terminating proxy this is what makes Request::secure()
        // truthful — the HSTS branch of SetSecurityHeaders and the
        // session cookie's secure flag both depend on it.
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

        $middleware->alias([
            'entity' => EnsureUserHasEntity::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Prepended so it wraps PreventRequestsDuringMaintenance —
        // maintenance 503s carry the hardening headers too.
        $middleware->prepend(SetSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
