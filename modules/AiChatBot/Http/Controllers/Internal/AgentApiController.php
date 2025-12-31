<?php

namespace Modules\AiChatBot\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\Billing\Models\Subscription;

class AgentApiController extends Controller
{
    public function getUserSubscription(Request $request)
    {
        $token = $request->bearerToken();
        $data = Cache::get("agent_token:{$token}");

        if (!$data) {
            return response()->json(['error' => 'Token invalid or expired'], 401);
        }

        $subscription = Subscription::where('user_id', $data['user_id'])->first();

        if (!$subscription) {
            return response()->json(['error' => 'No subscription found for this user'], 404);
        }

        return response()->json([
            'message' => 'User subscription retrieved successfully',
            'subscription' => $subscription,
            'status' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
