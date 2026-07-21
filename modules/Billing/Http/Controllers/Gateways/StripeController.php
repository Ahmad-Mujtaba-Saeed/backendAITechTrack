<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Illuminate\Support\Facades\Log;
class StripeController extends Controller
{

    public function getSubscriptionDetails(Request $request)
    {
        $user = Auth::user();

        $subscription = Subscription::where('user_id', $user->id)
            ->with('plan')
            ->latest()
            ->first();

        if (!$subscription || !$subscription->type_id) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            // $subscriptionStripe = \Stripe\Subscription::retrieve([
            //     'id' => $subscription->sub_id,
            //     'expand' => []
            // ]);

            return response()->json([
                'subscription' => $subscription,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve subscription details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

public function createSubscriptionSession(Request $request, $planId)
{
    \Log::info('START createSubscriptionSession', [
        'user_id' => Auth::id(),
        'plan_id' => $planId,
        'request' => $request->all()
    ]);

    $plan = Plan::find($planId);

    if (!$plan || $plan->is_active == 0) {

        \Log::error('Plan not found or inactive', [
            'plan_id' => $planId
        ]);

        return response()->json([
            'error' => 'Plan not found or inactive plan',
        ], 400);
    }


    \Log::info('Plan found', [
        'plan_id' => $plan->id,
        'plan_name' => $plan->name,
        'stripe_price_id' => $plan->stripe_price_id
    ]);


    Stripe::setApiKey(config('services.stripe.secret'));

    $user = Auth::user();


    \Log::info('Authenticated user', [
        'user_id' => $user->id,
        'email' => $user->email,
        'stripe_customer_id' => $user->stripe_customer_id
    ]);


    $customerId = $user->stripe_customer_id;


    if (!$customerId) {

        try {

            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'phone' => $user->phone,
                'metadata' => [
                    'user_id' => $user->id
                ]
            ]);

            $customerId = $customer->id;

            $user->stripe_customer_id = $customerId;
            $user->save();


            \Log::info('Stripe customer created', [
                'customer_id' => $customerId,
                'user_id' => $user->id
            ]);


        } catch (\Exception $e) {

            \Log::error('Stripe customer creation failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error'=>'Failed customer creation'
            ],500);
        }
    }



    $hasExistingSubscription = false;
    $hasUsedTrial = false;
    $hasPaymentMethods = false;



    try {

        $subscriptions = \Stripe\Subscription::all([
            'customer'=>$customerId,
            'status'=>'all',
            'limit'=>10
        ]);


        \Log::info('Stripe existing subscriptions', [
            'customer_id'=>$customerId,
            'subscriptions'=>$subscriptions->data
        ]);



        $paymentMethods = \Stripe\PaymentMethod::all([
            'customer'=>$customerId,
            'type'=>'card'
        ]);


        $hasPaymentMethods = count($paymentMethods->data)>0;


        \Log::info('Stripe payment methods',[
            'count'=>count($paymentMethods->data)
        ]);



    } catch(\Exception $e){

        \Log::error('Stripe history check failed',[
            'error'=>$e->getMessage()
        ]);

    }



    $shouldOfferTrial =
        $request->isFreeTrial &&
        !$hasUsedTrial &&
        !$hasExistingSubscription &&
        !$hasPaymentMethods;



    \Log::info('Trial decision',[
        'isFreeTrial'=>$request->isFreeTrial,
        'hasUsedTrial'=>$hasUsedTrial,
        'hasExistingSubscription'=>$hasExistingSubscription,
        'hasPaymentMethods'=>$hasPaymentMethods,
        'final_trial'=>$shouldOfferTrial
    ]);



    $subscriptionData = [
        'metadata'=>[
            'type'=>'subscription',
            'user_id'=>$user->id,
            'plan_id'=>$plan->id
        ]
    ];



    if($shouldOfferTrial){
        $subscriptionData['trial_period_days']=7;
    }



    try {


        $session = Session::create([

            'payment_method_types'=>['card'],

            'mode'=>'subscription',

            'customer'=>$customerId,

            'line_items'=>[
                [
                    'price'=>$plan->stripe_price_id,
                    'quantity'=>1
                ]
            ],

            'allow_promotion_codes'=>true,

            'subscription_data'=>$subscriptionData,

            'success_url'=>env('Frontend_URL_LIVE').'/welcome?session_id={CHECKOUT_SESSION_ID}',

            'cancel_url'=>env('Frontend_URL_LIVE'),

        ]);



        \Log::info('Stripe checkout session created',[
            'session_id'=>$session->id,
            'customer'=>$customerId,
            'subscription'=>$session->subscription,
            'metadata'=>$session->subscription_data ?? null
        ]);



    } catch(\Exception $e){

        \Log::error('Checkout session failed',[
            'error'=>$e->getMessage()
        ]);

        return response()->json([
            'error'=>$e->getMessage()
        ],500);

    }



    return response()->json([

        'sessionId'=>$session->id,

        'checkoutUrl'=>$session->url,

        'hasTrial'=>$shouldOfferTrial

    ]);

}

    public function cancelSubscription(Request $request)
    {
    $user = Auth::user();

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

        $subscriptionStripe =  \Stripe\Subscription::retrieve([
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
    }

    public function getPaymentMethod(Request $request)
    {
        $user = Auth::user();

        $subscription = Subscription::where('user_id', $user->id)
            ->latest()
            ->first();

        if (!$subscription || !$subscription->cus_id) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        
        try {
            $customer = \Stripe\Customer::retrieve($subscription->cus_id);
            
            // Get default payment method ID
            $defaultPaymentMethodId = $customer->invoice_settings->default_payment_method;

            // return $defaultPaymentMethodId;
            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $subscription->cus_id,
                'type' => 'card',
            ]);            
            foreach ($paymentMethods->data as $pm) {
                $isDefault = ($pm->id == $defaultPaymentMethodId);
                $pm->default = $isDefault;
            }
            return response()->json($paymentMethods);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve payment method.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePaymentMethod(Request $request, $id)
    {
        $user = Auth::user();

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($id);
            $paymentMethod->detach();

            return response()->json(['message' => 'Payment method deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payment method.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createSetupIntent($customerId)
    {
    Stripe::setApiKey(config('services.stripe.secret'));

    $intent = \Stripe\SetupIntent::create([
        'customer' => $customerId,
        'payment_method_types' => ['card'],
    ]);

    return response()->json([
        'clientSecret' => $intent->client_secret,
    ]);
   }

   public function makeDefaultPaymentMethod(Request $request, $customerId)
   {

    Stripe::setApiKey(config('services.stripe.secret'));

    \Stripe\Customer::update($customerId, [
        'invoice_settings' => [
            'default_payment_method' => $request->payment_method_id,
        ],
    ]);   

    return response()->json(['message' => 'Payment method updated successfully.']);
    }

    public function changePlan(Request $request, $planId)
    {
        $plan = Plan::find($planId);
        if(!$plan){
            return response()->json([
                'message' => 'Plan not found',
            ], 404);
        }
        $user = Auth::user();
        $subscription = Subscription::where('user_id', $user->id)
            ->latest()
            ->first();
            
        if(!$subscription){
            return response()->json([
                'message' => 'Subscription not found',
            ], 404);
        }
        
        $subscriptionId = $subscription->sub_id;

        Stripe::setApiKey(config('services.stripe.secret'));
        // Retrieve the current subscription
        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
    
        // Update the subscription with new plan
        $updated = \Stripe\Subscription::update($subscriptionId, [
            'items' => [[
                'id' => $subscription->items->data[0]->id, // keep same item
                'price' => $plan->stripe_price_id, // new plan price ID
            ]],
            'proration_behavior' => 'create_prorations', // immediate adjustment
        ]);
    
        return $updated;
    }

    public function getStripeCredit()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    
        $user = Auth::user();
        if (!$user->stripe_customer_id) {
            return response()->json(['credit' => 0]);
        }
    
        $customer =  \Stripe\Customer::retrieve($user->stripe_customer_id);
    
        // Stripe stores balance in CENTS & NEGATIVE means customer has CREDIT
        $credit = $customer->balance < 0 ? abs($customer->balance) / 100 : 0;
    
        return response()->json([
            'credit' => $credit,
        ]);
    }
}