<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Services\StoreReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StoreReportController extends Controller
{
    public function inventory(Request $request, StoreReportService $reports): Response
    {
        return $reports->inventory($request->user(), $this->format($request));
    }

    public function sales(Request $request, StoreReportService $reports): Response
    {
        return $reports->sales($request->user(), $this->format($request));
    }

    private function format(Request $request): string
    {
        return $request->string('format')->toString() === 'pdf' ? 'pdf' : 'xlsx';
    }
}
