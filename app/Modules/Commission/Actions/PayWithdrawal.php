<?php

declare(strict_types=1);

namespace App\Modules\Commission\Actions;

use App\Models\User;
use App\Modules\Commission\Models\Commission;
use App\Modules\Commission\Models\WithdrawalRequest;
use App\Shared\Enums\CommissionStatus;
use App\Shared\Enums\WithdrawalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayWithdrawal
{
    public function execute(WithdrawalRequest $withdrawal, User $admin): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawal, $admin) {
            /** @var WithdrawalRequest $locked */
            $locked = WithdrawalRequest::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== WithdrawalStatus::Requested) {
                throw ValidationException::withMessages([
                    'status' => ['Solo se puede confirmar el pago de una solicitud pendiente.'],
                ]);
            }

            $remaining = round((float) $locked->amount, 2);
            $covered = 0.0;
            $commissionIds = [];

            $commissions = Commission::query()
                ->where('referrer_id', $locked->user_id)
                ->where('status', CommissionStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($commissions as $commission) {
                $amount = round((float) $commission->amount, 2);
                if ($amount > $remaining) {
                    break;
                }

                $commission->forceFill([
                    'status' => CommissionStatus::Paid,
                    'paid_at' => now(),
                    'approved_by' => $admin->id,
                ])->save();

                $remaining = round($remaining - $amount, 2);
                $covered = round($covered + $amount, 2);
                $commissionIds[] = $commission->id;

                if ($remaining <= 0) {
                    break;
                }
            }

            $meta = $locked->meta ?? [];
            $meta['paid_commission_ids'] = $commissionIds;
            $meta['covered_amount'] = $covered;

            $locked->forceFill([
                'status' => WithdrawalStatus::Paid,
                'paid_at' => now(),
                'processed_at' => now(),
                'processed_by' => $admin->id,
                'meta' => $meta,
            ])->save();

            return $locked->fresh(['user', 'processedBy']);
        });
    }
}
