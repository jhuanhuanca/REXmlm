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

class SubscriptionRenewalReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Subscription $subscription,
        public float $amount,
        public string $chargeDate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'En 2 días cobramos tu plan REXmlm (US$ '.number_format($this->amount, 2).')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-renewal',
        );
    }
}
