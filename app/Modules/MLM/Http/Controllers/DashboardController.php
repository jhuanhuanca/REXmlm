<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commission\Models\Commission;
use App\Modules\MLM\Services\DashboardMetricsService;
use App\Modules\MLM\Services\TeamCrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardMetricsService $metrics): JsonResponse
    {
        return response()->json($metrics->summary($request->user()));
    }

    public function team(Request $request, TeamCrmService $crm): JsonResponse
    {
        $members = $crm->list($request->user());

        return response()->json($members);
    }

    public function commissions(Request $request): JsonResponse
    {
        $commissions = Commission::query()
            ->with('referred:id,name,email')
            ->where('referrer_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($commissions);
    }
}
