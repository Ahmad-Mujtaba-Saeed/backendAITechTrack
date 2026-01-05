<?php

namespace Modules\AiChatBot\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Subscription;
use Modules\User\Models\User;
use Stripe\Stripe;

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

    public function cancelUserSubscription(Request $request)
    {
        $token = $request->bearerToken();

        $data = Cache::get("agent_token:{$token}");

        if (!$data) {
            return response()->json(['error' => 'Token invalid or expired'], 401);
        }

        $user = User::find($data['user_id']);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($request->immediately == 0) {
            $subscription = Subscription::where('user_id', $user->id)
                ->latest()
                ->first();

            if (!$subscription || !$subscription->sub_id) {
                return response()->json(['message' => 'Active subscription not found.'], 404);
            }

            Stripe::setApiKey(config('services.stripe.secret'));

            try {
                \Stripe\Subscription::update(
                    $subscription->sub_id,
                    ['cancel_at_period_end' => true]
                );

                $subscriptionStripe = \Stripe\Subscription::retrieve([
                    'id' => $subscription->sub_id,
                    'expand' => []
                ]);

                $subscription->update([
                    'ends_at' => \Carbon\Carbon::createFromTimestamp($subscriptionStripe->cancel_at),
                    'cancel_at_period_end' => 1,
                ]);

                return response()->json(['message' => 'Subscription will be cancelled at period end.']);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Failed to cancel subscription.',
                    'error' => $e->getMessage()
                ], 500);
            }

            return response()->json([
                'message' => 'Subscription will be cancelled at period end.',
                'status' => 'success',
                'timestamp' => now()->toISOString(),
            ]);
        } else {
            if ($user->stripe_customer_id) {
                try {
                    $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

                    // First, cancel any active subscriptions
                    $subscriptions = $stripe->subscriptions->all(['customer' => $user->stripe_customer_id]);
                    foreach ($subscriptions->autoPagingIterator() as $subscription) {
                        if ($subscription->status !== 'canceled') {
                            $stripe->subscriptions->cancel($subscription->id);
                        }
                    }
                } catch (\Exception $e) {
                    // Log the error but don't fail the entire deletion process
                    \Log::error('Error cleaning up Stripe customer: ' . $e->getMessage());
                }
            }
            return response()->json([
                'message' => 'Subscription cancelled immediately. It may take a few minutes to process.',
                'status' => 'success',
                'timestamp' => now()->toISOString(),
            ]);
        }
    }
}
