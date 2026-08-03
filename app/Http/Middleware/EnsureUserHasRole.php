<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (! $user->actif) {
            return response()->json(['message' => 'Compte désactivé.'], 403);
        }

        $allowedRoles = array_map('strtoupper', $roles);

        if (! in_array(strtoupper((string) $user->role), $allowedRoles, true)) {
            return response()->json(['message' => 'Accès interdit.'], 403);
        }

        return $next($request);
    }
}
