<?php

declare(strict_types=1);

namespace App\Modules\Commission\Jobs;

use App\Mail\CommissionAccruedMail;
use App\Modules\Commission\Models\Commission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyReferrerOfCommission implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Commission $commission,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->commission->loadMissing('referrer');

        if (! $this->commission->referrer?->email) {
            return;
        }

        try {
            Mail::to($this->commission->referrer->email)->send(
                new CommissionAccruedMail($this->commission)
            );
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar el aviso de comisión.', [
                'commission_id' => $this->commission->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
