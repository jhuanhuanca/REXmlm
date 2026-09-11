<?php

declare(strict_types=1);

namespace App\Modules\Tools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tools\Services\CompanyToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyToolsController extends Controller
{
    public function imcPackages(Request $request, CompanyToolsService $tools): JsonResponse
    {
        return response()->json($tools->imcPackages($request->user()));
    }

    public function wellnessNeeds(Request $request, CompanyToolsService $tools): JsonResponse
    {
        return response()->json($tools->wellnessNeeds($request->user()));
    }

    public function documents(Request $request, CompanyToolsService $tools): JsonResponse
    {
        $type = $request->string('file_type')->toString() ?: $request->string('kind')->toString();

        return response()->json($tools->documents($request->user(), $type !== '' ? $type : null));
    }
}
