<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;

class DashboardContrller extends Controller
{

    public function getPaymentsData(Request $request)
    {
        $period = $request->get('period', 'daily'); // daily, weekly, monthly, yearly
        $limit = $request->get('limit', 30); // number of periods to show
        
        $query = Payment::where('payment_status', 'paid')
            ->whereNotNull('created_at');
            
        switch ($period) {
            case 'daily':
                $payments = $query->selectRaw('DATE(created_at) as period, COUNT(*) as count, COUNT(related_type) as membership_count, SUM(payment_amount) as total')
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->limit($limit)
                    ->get();
                break;
                
            case 'weekly':
                $payments = $query->selectRaw('YEARWEEK(created_at) as period, COUNT(*) as count, COUNT(related_type) as membership_count, SUM(payment_amount) as total')
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->limit($limit)
                    ->get();
                break;
                
            case 'monthly':
                $payments = $query->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as period, COUNT(*) as count, COUNT(related_type) as membership_count, SUM(payment_amount) as total')
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->limit($limit)
                    ->get();
                break;
                
            case 'yearly':
                $payments = $query->selectRaw('YEAR(created_at) as period, COUNT(*) as count, COUNT(related_type) as membership_count, SUM(payment_amount) as total')
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->limit($limit)
                    ->get();
                break;
                
            default:
                $payments = $query->selectRaw('DATE(created_at) as period, COUNT(*) as count, COUNT(related_type) as membership_count, SUM(payment_amount) as total')
                    ->groupBy('period')
                    ->orderBy('period', 'desc')
                    ->limit($limit)
                    ->get();
        }

        return response()->json([
            'period' => $period,
            'data' => $payments,
            'summary' => [
                'total_payments' => $payments->sum('count'),
                'total_amount' => $payments->sum('total'),
                'average_amount' => $payments->avg('total'),
                'membership_count' => $payments->sum('membership_count'),
            ]
        ]);
    }


    public function recentSubscriptions()
    {
        $subscriptions = Subscription::with('user','plan')->orderBy('created_at', 'desc')->take(5)->get();

        return response()->json([
            'subscriptions' => $subscriptions,
            'total_subscriptions' => Subscription::count(),
            'active_subscriptions' => Subscription::where('status','active')->count(),
            'cancelled_subscriptions' => Subscription::where('status','cancelled')->count(),
            'trial_subscriptions' => Subscription::where('status','trialing')->count()
        ]);
    }
}
