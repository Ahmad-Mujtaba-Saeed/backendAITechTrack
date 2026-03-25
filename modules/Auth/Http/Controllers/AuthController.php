<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Stripe\Stripe;
use Stripe\Customer;
use Modules\AccessControl\Models\Role;




class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|min:3',
            'email' => 'required|string|email|max:100|unique:users',
            'password' => 'required|string|min:8|max:18|confirmed',
        ]);


        Stripe::setApiKey(config('services.stripe.secret'));

        $stripeCustomer = null;
        
        try {
            $stripeCustomer = Customer::create([
                'email' => $request->email,
                'name' => $request->name,
                'metadata' => [
                    'user_type' => 'registered_user'
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Stripe customer creation failed during registration', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'stripe_customer_id' => $stripeCustomer ? $stripeCustomer->id : null,
        ]);

        $userRole = Role::where('slug', 'user')->first();

        // Assign user role
        $user->roles()->sync([$userRole->id]);

       


        return response()->json([
            'status' => true,
            'verification_required' => false,
            'user' => $user,
            'access_token' => $user->createToken('auth_token')->plainTextToken,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request)
{    
    // Validate input
    $request->validate([
        'login' => 'required|string|max:100', // can be email or username
        'password' => 'required|string|min:8|max:18',
    ]);

    $loginInput = $request->login;
    $password = $request->password;

    // Determine if input is email or username
    $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

    // Attempt login
    $loginSuccessful = Auth::attempt([$fieldType => $loginInput, 'password' => $password]);

    if (!$loginSuccessful) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid credentials',
        ], 401);
    }

    $user = Auth::user();

    if (!$user->is_active) {
        return response()->json([
            'status' => false,
            'message' => 'User is disabled by admin',
        ], 401);
    }

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'status' => true,
        'message' => 'User logged in successfully',
        'user' => $user,
        'access_token' => $token,
        'token_type' => 'Bearer',
    ]);
}


    protected function logLoginAttempt(Request $request, $user, $successful)
    {
        try {
            $ip = $request->ip();
            
            // Simple location detection using IP-API (no package required)
            $location = null;
            if (config('app.env') === 'production') {
                $ipInfo = @json_decode(file_get_contents("http://ip-api.com/json/{$ip}?fields=status,message,country,city"));
                if ($ipInfo && $ipInfo->status === 'success') {
                    $location = $ipInfo->country;
                    if (!empty($ipInfo->city)) {
                        $location .= ', ' . $ipInfo->city;
                    }
                }
            }

            LoginLog::create([
                'user_id' => $user ? $user->id : null,
                'ip_address' => $ip,
                'user_agent' => $request->userAgent(),
                'login_at' => now(),
                'login_successful' => $successful,
                'session_id' => session()->getId(),
                'remember' => false,
                'location' => $location,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log login attempt: ' . $e->getMessage());
        }
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('roles.permissions');
        return response()->json($user);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
