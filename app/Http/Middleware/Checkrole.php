<?php

// app/Http/Middleware/CheckRole.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $role
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $role)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Check if user has required role
        if ($request->user()->role !== $role) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. This action requires ' . $role . ' role.'
            ], 403);
        }

        // Additional check for merchants - must be verified
        if ($role === 'merchant' && !$request->user()->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Your merchant account is not verified yet. Please wait for admin verification.'
            ], 403);
        }

        // Check if user account is active
        if ($request->user()->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact admin.'
            ], 403);
        }

        return $next($request);
    }
}