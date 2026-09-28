<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report routes: an authenticated user without an assigned entity is
 * refused before any controller runs. The IFRS package's EntityScope
 * dereferences Auth::user()->entity->id on every IFRS model query, so
 * an entity-less user would otherwise 500 there — or, where a resolver
 * lends them the first entity, silently read that entity's books.
 */
class EnsureUserHasEntity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->entity) {
            abort(404, 'No IFRS entity assigned to your account.');
        }

        return $next($request);
    }
}
