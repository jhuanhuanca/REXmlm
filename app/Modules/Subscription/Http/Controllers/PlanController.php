<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Http\Resources\PlanResource;
use App\Modules\Subscription\Models\Plan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::query()->active()->orderBy('price')->get()
        );
    }
}
