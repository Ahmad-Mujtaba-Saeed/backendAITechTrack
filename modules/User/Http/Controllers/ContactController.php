<?php

namespace Modules\User\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\User\Http\Requests\StoreContactRequest;
use Modules\User\Mail\ContactSubmissionAcknowledgementMail;
use Modules\User\Mail\ContactSubmissionAdminMail;
use Modules\User\Models\Contact;

class ContactController extends Controller
{
   
    public function store(StoreContactRequest $request)
    {
        $contact = Contact::create($request->validated());

        $supportAddress = config('mail.support_address');

       
        try {
            Mail::to($supportAddress)->send(new ContactSubmissionAdminMail($contact));
        } catch (\Throwable $e) {
            Log::error('Failed to send contact enquiry to support', [
                'contact_id' => $contact->id,
                'support_address' => $supportAddress,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            Mail::to($contact->email, $contact->name)->send(new ContactSubmissionAcknowledgementMail($contact));
        } catch (\Throwable $e) {
            Log::error('Failed to send contact acknowledgement to visitor', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message submitted successfully.',
        ], 201);
    }
}
