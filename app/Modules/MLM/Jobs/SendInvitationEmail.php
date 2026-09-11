<?php

declare(strict_types=1);

namespace App\Modules\MLM\Jobs;

use App\Mail\InvitationMail;
use App\Modules\MLM\Models\Invitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendInvitationEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invitation $invitation,
        public string $plainToken,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->invitation->loadMissing('leader:id,name');

        $url = rtrim((string) config('rexmlm.frontend_url'), '/').'/register?token='.$this->plainToken;

        Mail::to($this->invitation->email)->send(
            new InvitationMail($this->invitation, $url)
        );
    }
}
