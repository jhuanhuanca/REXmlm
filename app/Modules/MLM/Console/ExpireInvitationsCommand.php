<?php

declare(strict_types=1);

namespace App\Modules\MLM\Console;

use App\Modules\MLM\Models\Invitation;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Console\Command;

class ExpireInvitationsCommand extends Command
{
    protected $signature = 'invitations:expire';

    protected $description = 'Marca como expiradas las invitaciones pendientes fuera de plazo';

    public function handle(): int
    {
        $expired = Invitation::query()
            ->where('status', InvitationStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => InvitationStatus::Expired]);

        $this->info("Invitaciones expiradas: {$expired}.");

        return self::SUCCESS;
    }
}
