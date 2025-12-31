<?php

namespace Modules\AiChatBot\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Subscription;

class AgentApiController extends Controller
{
    public function getUserSubscription(Request $request)
    {
        $token = $request->bearerToken();
        Log::info('AgentApiController@getUserSubscription: received request', [
            'token' => $token,
        ]);

        $data = Cache::get("agent_token:{$token}");
        Log::info('AgentApiController@getUserSubscription: cache lookup result', [
            'token_present' => (bool) $token,
            'data_null' => $data === null,
            'data_user_id' => $data['user_id'] ?? null,
            'data_scopes' => $data['scopes'] ?? null,
        ]);

        if (!$data) {
            Log::warning('AgentApiController@getUserSubscription: token invalid or expired');
            return response()->json(['error' => 'Token invalid or expired'], 401);
        }

        $subscription = Subscription::where('user_id', $data['user_id'])->first();
        Log::info('AgentApiController@getUserSubscription: subscription lookup', [
            'user_id' => $data['user_id'],
            'found' => (bool) $subscription,
        ]);

        if (!$subscription) {
            Log::warning('AgentApiController@getUserSubscription: no subscription found for user', [
                'user_id' => $data['user_id'],
            ]);
            return response()->json(['error' => 'No subscription found for this user'], 404);
        }

        Log::info('AgentApiController@getUserSubscription: success', [
            'user_id' => $data['user_id'],
            'subscription_id' => $subscription->id ?? null,
        ]);

        return response()->json([
            'message' => 'User subscription retrieved successfully',
            'subscription' => $subscription,
            'status' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
