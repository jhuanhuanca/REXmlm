<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CatalogProxyController extends Controller
{
    private const ALLOWED = '/^(companies|categories|products|technical-sheets|documents|compensation-plans|ranks|five-day-fundamentals|star-products|wellness-needs|imc-packages|starter-packages|support-tickets)(\/[A-Za-z0-9_-]+)*$/';

    public function __construct(
        private readonly CatalogClient $catalog,
    ) {}

    public function handle(Request $request, string $path): JsonResponse
    {
        if (! preg_match(self::ALLOWED, $path)) {
            throw new NotFoundHttpException('Ruta de catálogo no permitida.');
        }

        try {
            $isRead = in_array($request->method(), ['GET', 'HEAD', 'DELETE'], true);
            $forwarded = $this->catalog->forward(
                $request->method(),
                $path,
                $request->query(),
                $isRead ? [] : ($request->json()->all() ?: $request->except(['path'])),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $status = (int) $forwarded['status'];

        if ($status >= 200 && $status < 300 && ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            $this->forgetCompanyCaches($path);
        }

        return response()->json($forwarded['body'], $status >= 100 && $status < 600 ? $status : 502);
    }

    private function forgetCompanyCaches(string $path): void
    {
        if ($path !== 'companies' && ! str_starts_with($path, 'companies/')) {
            return;
        }

        $companyId = null;
        if (preg_match('#^companies/(\d+)$#', $path, $match) === 1) {
            $companyId = (int) $match[1];
        }

        $this->catalog->forgetCompanyCaches($companyId);
    }
}
