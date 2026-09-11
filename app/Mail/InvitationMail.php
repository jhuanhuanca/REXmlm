<?php

declare(strict_types=1);

namespace App\Mail;

use App\Modules\MLM\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $registerUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Te han invitado a unirte a '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invitation',
        );
    }
}
