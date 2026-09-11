<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commission\Actions\PayWithdrawal;
use App\Modules\Commission\Actions\RejectWithdrawal;
use App\Modules\Commission\Http\Resources\WithdrawalRequestResource;
use App\Modules\Commission\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WithdrawalRequest::query()
            ->with(['user:id,name,email', 'processedBy:id,name'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return WithdrawalRequestResource::collection($query->paginate(30))->response();
    }

    public function pay(int $id, Request $request, PayWithdrawal $action): JsonResponse
    {
        $withdrawal = WithdrawalRequest::query()->findOrFail($id);

        return (new WithdrawalRequestResource($action->execute($withdrawal, $request->user())))->response();
    }

    public function reject(int $id, Request $request, RejectWithdrawal $action): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $withdrawal = WithdrawalRequest::query()->findOrFail($id);

        return (new WithdrawalRequestResource(
            $action->execute($withdrawal, $request->user(), $data['notes'] ?? null),
        ))->response();
    }
}
