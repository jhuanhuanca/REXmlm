<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogClient;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class RegistrationOptionsController extends Controller
{
    public function __invoke(CatalogClient $catalog): JsonResponse
    {
        $companies = [];

        try {
            $companies = $catalog->getRegistrationOptions();
        } catch (RuntimeException) {
            $companies = [];
        }

        $googleClientId = (string) config('services.google.client_id');

        return response()->json([
            'countries' => config('rexmlm.countries'),
            'companies' => $companies,
            'google_client_id' => $googleClientId !== '' ? $googleClientId : null,
        ]);
    }
}
