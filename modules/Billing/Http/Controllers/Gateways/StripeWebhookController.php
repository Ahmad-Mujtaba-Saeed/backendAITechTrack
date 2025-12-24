<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
// use App\Mail\SubscriptionWelcomeMail;
// use App\Mail\SubscriptionCancelledMail;
// use Illuminate\Support\Facades\Mail;
use Exception;


class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = env('STRIPE_WEBHOOK_SECRET');
    
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Exception $e) {
            Log::error('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }


        try {
            switch ($event->type) {

                // case 'invoice.payment_succeeded' :
                //     $invoice = $event->data->object;

                    
                //     $email = $invoice->customer_email;
                    
                //     $user = User::where('email', $email)->first();
                    
                //     $price_id = $invoice->lines->data[0]->pricing->price_details->price;
                    

                //     $plan = Plan::where('stripe_price_id', $price_id)->first();

                //     $payment = Payment::create([
                //         'user_id' => $user->id,
                //         'related_type' => 'membership',
                //         'related_type_id' => $plan->id,
                //         'payment_amount' => $invoice->total / 100,  // Convert from cents to dollars
                //         'payment_transaction_id' => $invoice->id,
                //         'payment_gateway' => 'stripe',
                //         'payment_status' => $invoice->status,
                //         'payment_currency' => strtoupper($invoice->currency), // Ensure uppercase currency code
                //     ]);

                //     break;


                case 'customer.subscription.created' :
                    $subscription = $event->data->object;
                    $customerId = $subscription->customer;
                    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                    $customer = \Stripe\Customer::retrieve($customerId,[]);
                    $customer_email = $customer->email;
    
                    $user = User::where('email', $customer_email)->first();
                    if (!$user) {
                        Log::warning("Stripe webhook: User not found with email {$customer_email}");
                        return response()->json(['error' => 'User not found'], 404);
                    }
                   
    
                    \DB::beginTransaction();
    
                // Get the first subscription item (since there's only one)
                $subscriptionItem = $subscription->items->data[0];
                $plan = $subscriptionItem->plan;
                $price = $subscriptionItem->price;
                
                $plan = Plan::where('stripe_price_id', $price->id)->first();


                $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);

                $payment = Payment::create([
                    'user_id' => $user->id,
                    'related_type' => 'membership',
                    'related_type_id' => $plan->id,
                    'payment_amount' => $invoice->amount_paid / 100,  // Use actual amount paid from invoice
                    'payment_transaction_id' => $subscription->latest_invoice,
                    'payment_gateway' => 'stripe',
                    'payment_status' => $invoice->status,
                    'payment_currency' => strtoupper($invoice->currency), // Use currency from invoice
                ]);
                
                // Handle trial end date (can be null if no trial)
                $trialEndsAt = $subscription->trial_end 
                    ? \Carbon\Carbon::createFromTimestamp($subscription->trial_end)
                    : null;
                
                    
                 
                $subscriptionEndsAt = $subscriptionItem->current_period_end 
                    ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_end)
                    : null;
                    
               
                $subscriptionStartsAt = $subscriptionItem->current_period_start 
                    ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_start)
                    : null;
                
                
                    Subscription::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'sub_id' => $subscription->id,
                        ],
                        [
                            'name' => $plan->title,
                            'type' => 'membership',
                            'type_id' => $plan->id,
                            'payment_id' => $payment->id,
                            'cus_id' => $customerId,
                            'trial_ends_at' => $trialEndsAt,
                            'ends_at' => $subscriptionEndsAt,
                            'starts_at' => $subscriptionStartsAt,
                            'status' => $subscription->status
                        ]
                    );

                
                    // Mark in user's record that they've used trial
                    $user->trial_used = true;
                    $user->trial_used_at = now();
                    $user->save();
                    
                    // Send welcome email
                    // try {
                    //     Mail::to($user->email)->send(new SubscriptionWelcomeMail($user, $plan , $subscriptionEndsAt , $subscriptionStartsAt));
                    // } catch (\Exception $e) {
                    //     return response()->json(['error' => 'Failed to send welcome email ' . $e->getMessage()], 500);
                    //     // Log the error but don't fail the webhook
                    //     Log::error('Failed to send welcome email: ' . $e->getMessage());
                    // }
                    
                    \DB::commit();

                break;
                
                case 'customer.subscription.updated':
                    $subscription = $event->data->object;
                    $customerId = $subscription->customer;
                    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                    $customer = \Stripe\Customer::retrieve($customerId,[]);
                    $customer_email = $customer->email;
    
                    $user = User::where('email', $customer_email)->first();
                    if (!$user) {
                        Log::warning("Stripe webhook: User not found with email {$customer_email}");
                        return response()->json(['error' => 'User not found'], 404);
                    }
                   
    
                    \DB::beginTransaction();
    
                // Get the first subscription item (since there's only one)
                $subscriptionItem = $subscription->items->data[0];
                $plan = $subscriptionItem->plan;
                $price = $subscriptionItem->price;

                $plan = Plan::where('stripe_price_id', $price->id)->first();

                $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);

                
                $payment = Payment::create([
                    'user_id' => $user->id,
                    'related_type' => 'membership',
                    'related_type_id' => $plan->id,
                    'payment_amount' => $invoice->amount_paid / 100,  // Use actual amount paid from invoice
                    'payment_transaction_id' => $subscription->latest_invoice,
                    'payment_gateway' => 'stripe',
                    'payment_status' => $invoice->status,
                    'payment_currency' => strtoupper($invoice->currency), // Use currency from invoice
                ]);
                
                // Handle trial end date (can be null if no trial)
                $trialEndsAt = $subscription->trial_end 
                ? \Carbon\Carbon::createFromTimestamp($subscription->trial_end)
                : null;
                
                
                
                $subscriptionEndsAt = $subscriptionItem->current_period_end 
                ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_end)
                : null;
                
                $subscriptionStartsAt = $subscriptionItem->current_period_start 
                    ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_start)
                    : null;
                
                
                    
                    Subscription::updateOrCreate([
                        'user_id' => $user->id,
                        'sub_id' => $subscription->id,
                    ],[
                        'name' => $plan->title,
                    'type' => 'membership',
                    'type_id' => $plan->id,
                    'payment_id' => $payment->id,
                    'cus_id' => $customerId,
                    'trial_ends_at' => $trialEndsAt,
                    'ends_at' => $subscriptionEndsAt,
                    'starts_at' => $subscriptionStartsAt,
                    'status' => $subscription->status
                ]);
                
    
    

                    $user->save();
                    \DB::commit();
                    
                    break;
    
    
    
                case 'checkout.session.completed' :
                    $session = $event->data->object;
                    $customer_email = $session->customer_email;
    
                    $user = User::where('email', $customer_email)->first();
                    if (!$user) {
                        Log::warning("Stripe webhook: User not found with email {$customer_email}");
                        return response()->json(['error' => 'User not found'], 404);
                    }
    
                    if (isset($session->metadata->type) && $session->metadata->type == 'ticket') {
                        $eventId = $session->metadata->event_id;
                        $ticketTypeId = $session->metadata->ticket_type_id;
    
                    }
                    
                    break;
    
                
                    case 'customer.subscription.deleted':
                        $session = $event->data->object;
                    
                        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                        $stripeCustomer = \Stripe\Customer::retrieve($session->customer);
                        $customer_email = $stripeCustomer->email ?? null;
                    
                        if (!$customer_email) {
                            Log::warning("Stripe webhook: Email not found for customer ID {$session->customer}");
                            return response()->json(['error' => 'Email not found'], 404);
                        }
                    
                        $user = User::where('email', $customer_email)->first();
                        if (!$user) {
                            Log::warning("Stripe webhook: User not found with email {$customer_email}");
                            return response()->json(['error' => 'User not found'], 404);
                        }
                    
                        // Mark subscription as cancelled in your DB
                        $localSubscription = Subscription::where('user_id', $user->id)
                            ->where('sub_id', $session->id)
                            ->latest()
                            ->first();


                        if ($localSubscription) {
                            $localSubscription->status = 'cancelled';
                            $localSubscription->ends_at = now();
                            $localSubscription->save();
                            
                            // Get the plan details
                            $plan = Plan::find($localSubscription->type_id);
                            
                            // Send cancellation email
                            // try {
                            //     Mail::to($user->email)->send(new SubscriptionCancelledMail(
                            //         $user, 
                            //         $plan ?? null, 
                            //         now()
                            //     ));
                            // } catch (\Exception $e) {
                            //     // Log the error but don't fail the webhook
                            //     Log::error('Failed to send cancellation email: ' . $e->getMessage());
                            // }
                        }
                    
                        break;
                    
    
                default:
                    Log::info('Stripe webhook: Unhandled event type ' . $event->type);
                    break;
            }
        } catch (\Exception $ex) {
            \DB::rollBack();
            Log::error('Stripe webhook error: ' . $ex->getMessage());
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    
        return response()->json(['status' => 'success']);
    }
}
