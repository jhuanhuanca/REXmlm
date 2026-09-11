<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commission\Actions\RequestWithdrawal;
use App\Modules\Commission\Http\Resources\WithdrawalRequestResource;
use App\Modules\Commission\Models\WithdrawalRequest;
use App\Modules\Commission\Services\WithdrawalBalanceService;
use App\Modules\MLM\Http\Requests\StoreWithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function balance(Request $request, WithdrawalBalanceService $balance): JsonResponse
    {
        $user = $request->user();
        $snapshot = $balance->forUser($user);
        $snapshot['whatsapp'] = $balance->whatsappFor($user);
        $snapshot['email'] = $user->email;

        return response()->json($snapshot);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = WithdrawalRequest::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return WithdrawalRequestResource::collection($rows)->response();
    }

    public function store(StoreWithdrawalRequest $request, RequestWithdrawal $action): JsonResponse
    {
        $row = $action->execute($request->user(), $request->validated());

        return (new WithdrawalRequestResource($row))
            ->response()
            ->setStatusCode(201);
    }
}
