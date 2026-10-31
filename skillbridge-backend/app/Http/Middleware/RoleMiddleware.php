<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()->role !== 'admin' && $request->user()->moderation_status === 'suspended') {
            return response()->json([
                'message' => 'Your account is suspended and cannot perform marketplace actions.',
                'code' => 'ACCOUNT_SUSPENDED',
            ], 403);
        }
        if ($request->user()->role !== $role) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        return $next($request);
    }
}
