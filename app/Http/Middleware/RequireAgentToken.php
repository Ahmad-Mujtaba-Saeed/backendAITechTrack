<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

class RequireAgentToken
{
    public function handle($request, Closure $next, ...$requiredScopes)
    {
        $token = $request->bearerToken();

        $data = Cache::get("agent_token:{$token}");

        if (!$data) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $data['scopes'])) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        }

        $request->merge(['agent_user_id' => $data['user_id']]);

        return $next($request);
    }
}
