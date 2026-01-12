<?php

namespace Modules\Billing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewSubscriptionAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $plan;
    public $subscriptionEndsAt;
    public $subscriptionStartsAt;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $plan, $subscriptionEndsAt, $subscriptionStartsAt)
    {
        $this->user = $user;
        $this->plan = $plan;
        $this->subscriptionEndsAt = $subscriptionEndsAt;
        $this->subscriptionStartsAt = $subscriptionStartsAt;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New User Subscription, ' . $this->user->name . ' — ' . config('app.name') . ' ',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'billing::emails.new-subscription-admin',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
