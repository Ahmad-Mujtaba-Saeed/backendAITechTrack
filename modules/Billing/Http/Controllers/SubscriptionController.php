<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\Subscription;

class SubscriptionController extends Controller
{
    public function getAllSubscriptions(Request $request)
    {
        $query = Subscription::with(['user', 'plan', 'payments']);

        // Search by subscription ID, customer ID, user name, or email
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('sub_id', 'like', "%{$search}%")
                  ->orWhere('cus_id', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $subscriptions = $query->orderBy('created_at', 'desc')
                               ->paginate($perPage);

        // Get unique status values for filtering
        $uniqueStatuses = Subscription::distinct()->pluck('status')->filter()->values();

        return response()->json([
            'status' => true,
            'subscriptions' => $subscriptions,
            'unique_statuses' => $uniqueStatuses
        ]);
    }
}
