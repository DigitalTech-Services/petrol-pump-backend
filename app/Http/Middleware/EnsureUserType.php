<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    /**
     * Restrict a route to specific User::$type values (e.g. 'user' for owner, 'sub_user' for manager).
     * Usage: ->middleware('role:user') or ->middleware('role:sub_user')
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->type, $types, true)) {
            return response()->json([
                'status'  => 403,
                'success' => false,
                'message' => 'Access denied for this account type.',
                'data'    => null,
            ], 403);
        }

        return $next($request);
    }
}
