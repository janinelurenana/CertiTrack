<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $json = $request->is('api/*') || $request->expectsJson();
        if (! $user) {
            return $json ? response()->json(['message' => 'Unauthenticated.'], 401) : redirect('/login');
        }
        if (! $user->is_active) {
            return $json ? response()->json(['message' => 'Account is deactivated.'], 403) : abort(403, 'Account is deactivated.');
        }
        if (! in_array($user->role, $roles, true)) {
            return $json ? response()->json(['message' => 'You do not have permission to perform this action.'], 403) : abort(403, 'You do not have permission to view this page.');
        }
        return $next($request);
    }
}
