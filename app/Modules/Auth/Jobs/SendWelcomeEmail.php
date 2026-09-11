<?php

declare(strict_types=1);

namespace App\Modules\Auth\Jobs;

use App\Mail\WelcomeUserMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $url = rtrim((string) config('rexmlm.frontend_url'), '/').'/login';

        Mail::to($this->user->email)->send(
            new WelcomeUserMail($this->user, $url)
        );
    }
}
