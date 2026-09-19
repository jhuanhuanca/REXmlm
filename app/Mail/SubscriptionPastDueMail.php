<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Modules\Subscription\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionPastDueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Subscription $subscription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu suscripción REXmlm no se pudo cobrar. El panel quedó en pausa.',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-past-due',
        );
    }
}
