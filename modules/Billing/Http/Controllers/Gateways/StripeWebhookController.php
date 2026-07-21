<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Mail\SubscriptionCancelledMail;
use Modules\Billing\Mail\SubscriptionWelcomeMail;
use Modules\Billing\Mail\NewSubscriptionAdminMail;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\User\Models\User;
use Stripe\Exception\ApiErrorException;
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
             case 'invoice.payment_succeeded':

    Log::info('========== invoice.payment_succeeded START ==========');

    try {

        $invoice = $event->data->object;
        Log::info('Invoice received', ['invoice_id' => $invoice->id]);

        $email = $invoice->customer_email;
        Log::info('Customer Email', ['email' => $email]);

        $user = User::where('email', $email)->first();
        Log::info('User lookup', ['user' => $user]);

        if (!$user) {
            Log::warning('User not found');
            break;
        }

        $price_id = $invoice->lines->data[0]->pricing->price_details->price;
        Log::info('Price ID', ['price_id' => $price_id]);

        $plan = Plan::where('stripe_price_id', $price_id)->first();
        Log::info('Plan', ['plan' => $plan]);

        $subscriptionStripeId = $invoice->subscription
            ?? $invoice->parent?->subscription_details?->subscription
            ?? $invoice->lines->data[0]->parent?->subscription_item_details?->subscription
            ?? null;

        Log::info('Subscription ID', [
            'subscription_id' => $subscriptionStripeId
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'related_type' => 'membership',
            'related_type_id' => $plan?->id,
            'subscription_id' => $subscriptionStripeId,
            'payment_amount' => $invoice->total / 100,
            'payment_transaction_id' => $invoice->id,
            'payment_gateway' => 'stripe',
            'payment_status' => $invoice->status,
            'payment_currency' => strtoupper($invoice->currency),
        ]);

        Log::info('Payment Created', [
            'payment_id' => $payment->id
        ]);

    } catch (\Exception $e) {

        Log::error('invoice.payment_succeeded ERROR', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
            'trace' => $e->getTraceAsString(),
        ]);

    }

    Log::info('========== invoice.payment_succeeded END ==========');

    break;

                case 'invoice.payment_failed':
                    $invoice = $event->data->object;

                    $email = $invoice->customer_email;

                    $user = User::where('email', $email)->first();

                    if (!$user) {
                        // Optional: log and safely exit
                        break;
                    }

                    $price_id = $invoice->lines->data[0]->pricing->price_details->price;

                    $subscriptionStripeId = $invoice->subscription
                        ?? $invoice->parent?->subscription_details?->subscription
                        ?? $invoice->lines->data[0]->parent?->subscription_item_details?->subscription
                        ?? null;

                    $plan = Plan::where('stripe_price_id', $price_id)->first();

                    Payment::create([
                        'user_id' => $user->id,
                        'related_type' => 'membership',
                        'related_type_id' => $plan?->id,
                        'subscription_id' => $subscriptionStripeId,
                        'payment_amount' => $invoice->total / 100,
                        'payment_transaction_id' => $invoice->id,
                        'payment_gateway' => 'stripe',
                        'payment_status' => $invoice->status,
                        'payment_currency' => strtoupper($invoice->currency),
                    ]);

                    break;

               case 'customer.subscription.created':

Log::info('========== customer.subscription.created START ==========');

try {

    $subscription = $event->data->object;
    Log::info('Subscription object received', [
        'subscription_id' => $subscription->id
    ]);

    $customerId = $subscription->customer;
    Log::info('Customer ID', ['customer_id' => $customerId]);

    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
    Log::info('Stripe key set');

    $customer = \Stripe\Customer::retrieve($customerId, []);
    Log::info('Customer retrieved', [
        'customer' => $customer
    ]);

    $customer_email = $customer->email;
    Log::info('Customer Email', [
        'email' => $customer_email
    ]);

    $user = User::where('email', $customer_email)->first();
    Log::info('User lookup by email', [
        'user' => $user
    ]);

    if (!$user && !empty($customerId)) {

        Log::info('Searching user by stripe_customer_id');

        $user = User::where('stripe_customer_id', $customerId)->first();

        Log::info('User by stripe_customer_id', [
            'user' => $user
        ]);
    }

    if (!$user) {

        Log::warning('User NOT FOUND', [
            'customer_id' => $customerId,
            'email' => $customer_email
        ]);

        return response()->json(['received' => true], 200);
    }

    Log::info('Beginning DB Transaction');

    DB::beginTransaction();

    $subscriptionItem = $subscription->items->data[0];
    Log::info('Subscription Item', [
        'item' => $subscriptionItem
    ]);

    $price = $subscriptionItem->price;

    Log::info('Stripe Price', [
        'price_id' => $price->id
    ]);

    $plan = Plan::where('stripe_price_id', $price->id)->first();

    Log::info('Plan Found', [
        'plan' => $plan
    ]);

    if (!$plan) {
        Log::warning('Plan not found');
    }

    $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);

    Log::info('Latest Invoice', [
        'invoice_id' => $invoice->id
    ]);

    $trialEndsAt = $subscription->trial_end
        ? \Carbon\Carbon::createFromTimestamp($subscription->trial_end)
        : null;

    Log::info('Trial Ends', [
        'trial_end' => $trialEndsAt
    ]);

    $subscriptionEndsAt = $subscriptionItem->current_period_end
        ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_end)
        : null;

    Log::info('Subscription Ends', [
        'ends_at' => $subscriptionEndsAt
    ]);

    $subscriptionStartsAt = $subscriptionItem->current_period_start
        ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_start)
        : null;

    Log::info('Subscription Starts', [
        'starts_at' => $subscriptionStartsAt
    ]);

    $subscriptionModel = Subscription::updateOrCreate(
        [
            'user_id' => $user->id,
            'sub_id' => $subscription->id,
        ],
        [
            'name' => $plan->name,
            'type' => 'membership',
            'type_id' => $plan->id,
            'cus_id' => $customerId,
            'trial_ends_at' => $trialEndsAt,
            'starts_at' => $subscriptionStartsAt,
            'ends_at' => $subscriptionEndsAt,
            'status' => $subscription->status,
        ]
    );

    Log::info('Subscription Saved', [
        'subscription_model' => $subscriptionModel
    ]);

    $user->trial_used = true;
    $user->trial_used_at = now();
    $user->save();

    Log::info('User Updated');

    try {

        Mail::to($user->email)->send(
            new SubscriptionWelcomeMail(
                $user,
                $plan,
                $subscriptionEndsAt,
                $subscriptionStartsAt
            )
        );

        Log::info('Welcome Email Sent');

    } catch (\Exception $e) {

        Log::error('Welcome Email Failed', [
            'message' => $e->getMessage()
        ]);

    }

    try {

        $adminUser = User::whereHas('roles', function ($query) {
            $query->where('slug', 'admin');
        })->first();

        Log::info('Admin User', [
            'admin' => $adminUser
        ]);

        Mail::to($adminUser->email)->send(
            new NewSubscriptionAdminMail(
                $user,
                $plan,
                $subscriptionEndsAt,
                $subscriptionStartsAt
            )
        );

        Log::info('Admin Email Sent');

    } catch (\Exception $e) {

        Log::error('Admin Email Failed', [
            'message' => $e->getMessage()
        ]);

    }

    DB::commit();

    Log::info('Transaction Committed');

} catch (\Exception $e) {

    DB::rollBack();

    Log::error('customer.subscription.created ERROR', [
        'message' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile(),
        'trace' => $e->getTraceAsString(),
    ]);
}

Log::info('========== customer.subscription.created END ==========');

break;
                case 'customer.subscription.updated':
                    $subscription = $event->data->object;
                    $customerId = $subscription->customer;
                    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                    $customer = \Stripe\Customer::retrieve($customerId, []);
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

                    // $payment = Payment::create([
                    //     'user_id' => $user->id,
                    //     'related_type' => 'membership',
                    //     'related_type_id' => $plan->id,
                    //     'payment_amount' => $invoice->amount_paid / 100,  // Use actual amount paid from invoice
                    //     'payment_transaction_id' => $subscription->latest_invoice,
                    //     'payment_gateway' => 'stripe',
                    //     'payment_status' => $invoice->status,
                    //     'payment_currency' => strtoupper($invoice->currency), // Use currency from invoice
                    // ]);

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
                    ], [
                        'name' => $plan->name,
                        'type' => 'membership',
                        'type_id' => $plan->id,
                        'cus_id' => $customerId,
                        'trial_ends_at' => $trialEndsAt,
                        'ends_at' => $subscriptionEndsAt,
                        'starts_at' => $subscriptionStartsAt,
                        'status' => $subscription->status
                    ]);

                    $user->save();
                    \DB::commit();

                    break;

                case 'checkout.session.completed':
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
                        try {
                            Mail::to($user->email)->send(new SubscriptionCancelledMail(
                                $user,
                                $plan ?? null,
                                now()
                            ));
                        } catch (\Exception $e) {
                            // Log the error but don't fail the webhook
                            Log::error('Failed to send cancellation email: ' . $e->getMessage());
                        }
                    }

                    break;

                default:
                    Log::info('Stripe webhook: Unhandled event type ' . $event->type);
                    break;
            }
        } catch (\Exception $ex) {
            \DB::rollBack();
            Log::error('Stripe webhook error: ' . $ex->getMessage());
            return response()->json(['error' => 'Webhook processing failed', 'message' => $ex->getMessage()], 500);
        }

        return response()->json(['status' => 'success']);
    }
}
