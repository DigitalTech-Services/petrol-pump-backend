<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStationAssigned
{
    /**
     * Block a manager's writes until the owner assigns them a station — otherwise
     * their entries are saved with station_id = null and never show up when the
     * owner views that station.
     * Usage: ->middleware('station.assigned') after role:sub_user
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->type === 'sub_user' && !$user->station_id) {
            return response()->json([
                'status'  => 409,
                'success' => false,
                'message' => 'Your account is not assigned to a station yet. Ask the owner to assign one from the Managers page.',
                'data'    => ['code' => 'station_not_assigned'],
            ], 409);
        }

        return $next($request);
    }
}
