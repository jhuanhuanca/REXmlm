<?php

declare(strict_types=1);

namespace App\Mail;

use App\Modules\Commission\Models\Commission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommissionAccruedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Commission $commission,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nueva comisión en '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.commission-accrued',
        );
    }
}
