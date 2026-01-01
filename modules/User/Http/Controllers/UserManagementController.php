<?php

namespace Modules\User\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();
        if ($request->get('search')) {
            $query->where('name', 'like', '%' . $request->get('search') . '%')
                ->orWhere('email', 'like', '%' . $request->get('search') . '%')
                ->orWhere('phone', 'like', '%' . $request->get('search') . '%');
        }
        $users = $query->paginate($request->get('per_page', 10));
        return response()->json([
            'message' => 'User management endpoint', 
            'users' => $users
        ], 200);
    }

    public function delete(Request $request, $user_id){

        $user = User::find($user_id);
        
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Delete profile image if exists
            if ($user->profile_img) {
                Storage::disk('public')->delete($user->profile_img);
            }

            // Only process Stripe customer if they have a customer ID
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
                    
                    // Then delete the customer
                    // $stripe->customers->delete($user->stripe_customer_id);
                } catch (\Exception $e) {
                    // Log the error but don't fail the entire deletion process
                    \Log::error('Error cleaning up Stripe customer: ' . $e->getMessage());
                }
            }

            // Delete all API tokens (Sanctum)
            $user->tokens()->delete();

            // // Delete all subscriptions
            // $user->subscriptions()->delete();

            // // Delete all payments
            // $user->payments()->delete();

            // // Delete all interviews
            // $user->interviews()->delete();

            // // Delete all job applications
            // $user->jobApplications()->delete();

            // Delete all CV resumes (this will also trigger soft deletes if configured)
            // $user->Resume()->forceDelete(); // Use forceDelete to permanently remove

            // Finally, delete the user
            $user->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'User and all associated data deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function userSubscriptionStatus(Request $request, $user_id)
    {
        $user = User::find($user_id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }
        $subscriptions = $user->subscription()->with('history','payment')->get();
        
        return response()->json([
            'user' => $user,
            'subscriptions' => $subscriptions
        ]);
    }

    public function cancelSubscriptionImmediate(Request $request, $user_id)
    {
        $user = User::find($user_id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }


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
            'status' => true,
            'message' => 'All subscriptions cancelled immediately',
        ]);
    }
}
