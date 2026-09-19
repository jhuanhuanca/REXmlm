<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\StorePlanRequest;
use App\Modules\Subscription\Http\Resources\PlanResource;
use App\Modules\Subscription\Models\Plan;
use App\Shared\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->orderBy('sort_order')->orderBy('price')->get());
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(6));
        $data['currency'] = Currencies::COMMISSION;

        $plan = Plan::query()->create($data);

        return (new PlanResource($plan))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StorePlanRequest $request, int $id): PlanResource
    {
        $plan = Plan::query()->findOrFail($id);
        $payload = $request->validated();
        $payload['currency'] = Currencies::COMMISSION;
        $plan->update($payload);

        return new PlanResource($plan->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);

        if ($plan->subscriptions()->exists()) {
            throw ValidationException::withMessages([
                'plan' => ['No se puede eliminar un plan con suscripciones. Desactívalo.'],
            ]);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan eliminado']);
    }
}
