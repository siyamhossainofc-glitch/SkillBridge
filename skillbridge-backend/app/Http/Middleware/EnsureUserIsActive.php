<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->role !== 'admin' && $user->moderation_status === 'suspended') {
            return response()->json([
                'message' => 'Your account is suspended and cannot perform marketplace actions.',
                'code' => 'ACCOUNT_SUSPENDED',
            ], 403);
        }
        return $next($request);
    }
}
