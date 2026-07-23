<?php

namespace Modules\User\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\User\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
class UserController extends Controller
{
    public function getUser(Request $request)
    {
        $user = $request->user();
        return response()->json($user);
    }

    public function ProfileSettings(Request $request){
        $request->validate([
            "name" => "sometimes|string|max:100|min:3",
            "email" => "sometimes|email|unique:users,email," . auth()->id(),
            "profile_img" => "sometimes|image|mimes:jpeg,png,jpg,gif|max:2048",
            "bio" => "nullable|string|max:300",
            "lang" => "sometimes|string|max:10",
            "time_zone" => "sometimes|string|max:50",
            "email_notif" => "sometimes|boolean",
            "push_notif" => "sometimes|boolean"
        ]);

        $user = Auth::user();
        
        // Update basic info
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('bio')) {
            $user->bio = $request->bio;
        }
        
        if ($request->has('email') && $request->email !== $user->email) {
            $user->email = $request->email;
            // You might want to implement email verification here
        }

        // Update language preference
        if ($request->has('lang')) {
            $user->lang = $request->lang;
        }

        // Update timezone
        if ($request->has('time_zone')) {
            $user->time_zone = $request->time_zone;
        }

        // Update email notification preference
        if ($request->has('email_notif')) {
            $user->email_notif = $request->email_notif;
        }

        // Update push notification preference
        if ($request->has('push_notif')) {
            $user->push_notif = $request->push_notif;
        }

        // Handle profile image upload
        if ($request->hasFile('profile_img')) {
            // Delete old profile image if exists
            if ($user->profile_img) {
                Storage::disk('public')->delete($user->profile_img);
            }
            
            // Store the file in storage/app/public/profiles
            $path = $request->file('profile_img')->store('profiles', 'public');
            
            // Store the path in the database
            $user->profile_img = $path;
        }

        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Profile updated successfully',
            'user' => $user->fresh()
        ]);
    }

public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'subject' => 'required|string|max:255',
        'message' => 'required|string',
    ]);

    DB::table('contacts')->insert([
        'name' => $request->name,
        'email' => $request->email,
        'subject' => $request->subject,
        'message' => $request->message,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Email to Admin
    Mail::send([], [], function ($mail) use ($request) {
        $mail->to('support@techtrack.online') // Replace with your admin email
             ->subject('New Contact Form Submission')
             ->html("
                <h2>New Contact Form Submission</h2>
                <p><strong>Name:</strong> {$request->name}</p>
                <p><strong>Email:</strong> {$request->email}</p>
                <p><strong>Subject:</strong> {$request->subject}</p>
                <p><strong>Message:</strong><br>{$request->message}</p>
             ");
    });

    // Confirmation Email to User
    Mail::send([], [], function ($mail) use ($request) {
        $mail->to($request->email)
             ->subject('Thank You for Contacting Cv Builder')
             ->html("
                <h2>Thank You, {$request->name}!</h2>
                <p>We have received your message.</p>
                <p><strong>Subject:</strong> {$request->subject}</p>
                <p>Our team will get back to you as soon as possible.</p>
                <br>
                <p>Regards,<br>Cv Builder Team</p>
             ");
    });

    return response()->json([
        'success' => true,
        'message' => 'Message submitted successfully.',
    ], 201);
}

public function changePassword(Request $request)
{
    $request->validate([
        'current_password' => 'required',
        'new_password' => 'required|min:8|confirmed',
    ]);

    $user = Auth::user();

    // Check current password
    if (!Hash::check($request->current_password, $user->password)) {
        return response()->json([
            'status' => false,
            'message' => 'Current password is incorrect.'
        ], 400);
    }

    // Prevent using the same password
    if (Hash::check($request->new_password, $user->password)) {
        return response()->json([
            'status' => false,
            'message' => 'New password cannot be the same as the current password.'
        ], 400);
    }

    // Update password
    $user->password = Hash::make($request->new_password);
    $user->save();

    return response()->json([
        'status' => true,
        'message' => 'Password changed successfully.'
    ], 200);
}
}