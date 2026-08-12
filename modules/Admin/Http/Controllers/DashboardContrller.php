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


    
    public function RecentActivities(Request $request){
        $user = $request->user();
        
        // Get pagination parameters
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 10);
        
        // Get all audit records with pagination
        $activities = \OwenIt\Auditing\Models\Audit::with('user')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
        
        $formattedActivities = $activities->map(function ($activity) {
            $message = $this->formatActivityMessage($activity);
            
            return [
                'id' => $activity->id,
                'message' => $message,
                'created_at' => $activity->created_at,
                'event' => $activity->event,
                'auditable_type' => $activity->auditable_type,
                'ip_address' => $activity->ip_address,
                'user_agent' => $activity->user_agent,
                'user' => $activity->user ? [
                    'name' => $activity->user->name,
                    'email' => $activity->user->email
                ] : null
            ];
        });
        
        return response()->json([
            'status' => true,
            'activities' => $formattedActivities,
            'pagination' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'from' => $activities->firstItem(),
                'to' => $activities->lastItem()
            ]
        ]);
    }
    
    /**
     * Format activity message based on audit data
     */
    private function formatActivityMessage($activity)
    {
        $event = $activity->event;
        $type = class_basename($activity->auditable_type);
        $oldValues = $activity->old_values ?? [];
        $newValues = $activity->new_values ?? [];
        
        // Handle different entity types
        switch ($type) {
            case 'Resume':
                return $this->formatResumeActivity($event, $oldValues, $newValues);
                
            case 'Subscription':
                return $this->formatSubscriptionActivity($event, $oldValues, $newValues);
                
            case 'User':
                return $this->formatUserActivity($event, $oldValues, $newValues);
                
            case 'Payment':
                return $this->formatPaymentActivity($event, $oldValues, $newValues);
                
            default:
                return $this->formatGenericActivity($event, $type, $oldValues, $newValues);
        }
    }
    
    /**
     * Format resume-related activities
     */
    private function formatResumeActivity($event, $oldValues, $newValues)
    {
        switch ($event) {
            case 'created':
                return 'Created a new Resume';
                
            case 'updated':
                return 'Updated Resume details';
                
            case 'deleted':
                return 'Deleted a Resume';
                
            default:
                return "Performed {$event} action on Resume";
        }
    }
    
    /**
     * Format subscription-related activities
     */
    private function formatSubscriptionActivity($event, $oldValues, $newValues)
    {
        switch ($event) {
            case 'created':
                $planName = $newValues['plan_id'] ?? 'a plan';
                return "Subscribed to {$planName}";
                
            case 'updated':
                if (isset($newValues['status'])) {
                    $status = $newValues['status'];
                    switch ($status) {
                        case 'active':
                            return 'Subscription activated';
                        case 'cancelled':
                            return 'Cancelled subscription';
                        case 'expired':
                            return 'Subscription expired';
                        default:
                            return "Subscription status changed to {$status}";
                    }
                }
                return 'Updated subscription';
                
            case 'deleted':
                return 'Cancelled subscription';
                
            default:
                return "Performed {$event} action on Subscription";
        }
    }
    
    /**
     * Format user profile activities
     */
    private function formatUserActivity($event, $oldValues, $newValues)
    {
        switch ($event) {
            case 'updated':
                $changes = [];
                if (isset($newValues['name'])) {
                    $changes[] = 'name';
                }
                if (isset($newValues['email'])) {
                    $changes[] = 'email';
                }
                if (isset($newValues['profile_img'])) {
                    $changes[] = 'profile picture';
                }
                if (isset($newValues['bio'])) {
                    $changes[] = 'bio';
                }
                
                if (!empty($changes)) {
                    $changeList = implode(', ', $changes);
                    return "Updated profile: {$changeList}";
                }
                
                return 'Updated profile';
                
            case 'created':
                return 'Account created';
                
            default:
                return "Performed {$event} action on profile";
        }
    }
    
    /**
     * Format payment-related activities
     */
    private function formatPaymentActivity($event, $oldValues, $newValues)
    {
        switch ($event) {
            case 'created':
                $amount = $newValues['payment_amount'] ?? '';
                if ($amount) {
                    return "Made a payment of \${amount}";
                }
                return 'Made a payment';
                
            case 'updated':
                if (isset($newValues['payment_status'])) {
                    $status = $newValues['payment_status'];
                    return "Payment status updated to {$status}";
                }
                return 'Updated payment';
                
            default:
                return "Performed {$event} action on Payment";
        }
    }
    
    /**
     * Format generic activities for unknown entity types
     */
    private function formatGenericActivity($event, $type, $oldValues, $newValues)
    {
        switch ($event) {
            case 'created':
                return "Created a new {$type}";
                
            case 'updated':
                return "Updated {$type}";
                
            case 'deleted':
                return "Deleted {$type}";
                
            default:
                return "Performed {$event} action on {$type}";
        }
    }
}
