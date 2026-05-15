<?php

namespace App\Http\Middleware;

use App\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! $user->relationLoaded('role')) {
            $user?->load('role');
        }

        if (! $user?->role) {
            return ApiResponse::error('Forbidden', 403);
        }

        if (! in_array($user->role->slug, $roles, true)) {
            return ApiResponse::error('Forbidden', 403);
        }

        return $next($request);
    }
}
