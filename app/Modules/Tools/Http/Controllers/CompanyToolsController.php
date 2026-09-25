<?php

declare(strict_types=1);

namespace App\Modules\Tools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tools\CompanyToolCatalog;
use App\Modules\Tools\Services\CompanyToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyToolsController extends Controller
{
    public function available(Request $request, CompanyToolsService $tools): JsonResponse
    {
        return response()->json($tools->available($request->user()));
    }

    public function imcPackages(Request $request, CompanyToolsService $tools): JsonResponse
    {
        return $this->guarded($request, $tools, 'imc', fn () => $tools->imcPackages($request->user()));
    }

    public function wellnessNeeds(Request $request, CompanyToolsService $tools): JsonResponse
    {
        return $this->guarded($request, $tools, 'wellness', fn () => $tools->wellnessNeeds($request->user()));
    }

    public function documents(Request $request, CompanyToolsService $tools): JsonResponse
    {
        $type = $request->string('file_type')->toString() ?: $request->string('kind')->toString();
        $type = $type !== '' ? $type : null;
        $key = CompanyToolCatalog::documentKey($type);

        if ($key !== null && ! $tools->allows($request->user(), $key)) {
            return $this->disabled();
        }

        return response()->json($tools->documents($request->user(), $type));
    }

    /**
     * @param  callable(): array<string, mixed>  $payload
     */
    private function guarded(Request $request, CompanyToolsService $tools, string $key, callable $payload): JsonResponse
    {
        if (! $tools->allows($request->user(), $key)) {
            return $this->disabled();
        }

        return response()->json($payload());
    }

    private function disabled(): JsonResponse
    {
        return response()->json([
            'message' => 'Esta herramienta no está activa para tu empresa.',
            'code' => 'company_tool_disabled',
        ], 403);
    }
}
