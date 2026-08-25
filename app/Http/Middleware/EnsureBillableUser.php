<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureBillableUser
{
    /**
     * Block customer billing actions for internal/staff accounts.
     *
     * Admins run the platform, they are not customers of it: they must never be
     * put on a plan, sent to checkout, or given a Stripe customer / payment
     * method. This guards the routes that would otherwise create that state.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->hasRole('admin') || $user->hasPermission('system-internal')) {
            return response()->json([
                'message' => 'Billing does not apply to internal accounts.',
            ], 403);
        }

        return $next($request);
    }
}
