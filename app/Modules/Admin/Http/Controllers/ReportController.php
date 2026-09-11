<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\OverviewReportRequest;
use App\Modules\Admin\Services\AdminOverviewReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function overview(OverviewReportRequest $request, AdminOverviewReportService $reports): JsonResponse
    {
        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->toString())
            : now()->toImmutable()->startOfYear();
        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->string('to')->toString())
            : now()->toImmutable();
        $group = $request->string('group')->toString() ?: 'month';

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return response()->json($reports->build($from, $to, $group));
    }
}
