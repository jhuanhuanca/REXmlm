<?php

declare(strict_types=1);

namespace App\Modules\Commission\Actions;

use App\Models\User;
use App\Modules\Commission\Models\WithdrawalRequest;
use App\Shared\Enums\WithdrawalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectWithdrawal
{
    public function execute(WithdrawalRequest $withdrawal, User $admin, ?string $notes = null): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawal, $admin, $notes) {
            /** @var WithdrawalRequest $locked */
            $locked = WithdrawalRequest::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== WithdrawalStatus::Requested) {
                throw ValidationException::withMessages([
                    'status' => ['Solo se puede rechazar una solicitud pendiente.'],
                ]);
            }

            $locked->forceFill([
                'status' => WithdrawalStatus::Rejected,
                'processed_at' => now(),
                'processed_by' => $admin->id,
                'notes' => $notes ?: $locked->notes,
            ])->save();

            return $locked->fresh(['user', 'processedBy']);
        });
    }
}
