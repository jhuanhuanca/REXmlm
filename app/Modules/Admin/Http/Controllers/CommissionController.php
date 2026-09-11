<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commission\Models\Commission;
use App\Shared\Enums\CommissionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Commission::query()
            ->with(['referrer:id,name,email', 'referred:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('referrer_id')) {
            $query->where('referrer_id', $request->integer('referrer_id'));
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function pay(int $id): JsonResponse
    {
        $commission = Commission::query()->findOrFail($id);

        if (! in_array($commission->status, [CommissionStatus::Pending, CommissionStatus::Approved], true)) {
            throw ValidationException::withMessages([
                'status' => ['Solo se pueden pagar comisiones pendientes o aprobadas.'],
            ]);
        }

        $commission->forceFill([
            'status' => CommissionStatus::Paid,
            'paid_at' => now(),
        ])->save();

        return response()->json($commission);
    }

    public function cancel(int $id): JsonResponse
    {
        $commission = Commission::query()->findOrFail($id);

        if ($commission->status === CommissionStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => ['Una comisión pagada no se cancela: debe revertirse.'],
            ]);
        }

        $commission->forceFill([
            'status' => CommissionStatus::Cancelled,
        ])->save();

        return response()->json($commission);
    }
}
