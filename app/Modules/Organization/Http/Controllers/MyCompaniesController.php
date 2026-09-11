<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Resources\AuthUserResource;
use App\Modules\Organization\Actions\AddSecondaryCompanyAction;
use App\Modules\Organization\Actions\SwitchActiveCompanyAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyCompaniesController extends Controller
{
    public function store(Request $request, AddSecondaryCompanyAction $action): JsonResponse
    {
        $data = $request->validate([
            'catalog_company_id' => ['required', 'integer', 'min:1'],
            'catalog_rank_id' => ['nullable', 'integer', 'min:1'],
            'catalog_rank_name' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $action->handle(
            $request->user(),
            (int) $data['catalog_company_id'],
            isset($data['catalog_rank_id']) ? (int) $data['catalog_rank_id'] : null,
            $data['catalog_rank_name'] ?? null,
        );

        if (isset($result['checkout_url'])) {
            return response()->json([
                'checkout_url' => $result['checkout_url'],
            ]);
        }

        $request->user()->load([
            'roles',
            'store',
            'landingPage',
            'currentNetwork',
            'sponsor.store',
            'sponsor.landingPage',
            'organization',
            'companyMemberships',
        ]);

        return (new AuthUserResource($request->user()))
            ->response()
            ->setStatusCode(201);
    }

    public function activate(Request $request, SwitchActiveCompanyAction $action): AuthUserResource
    {
        $data = $request->validate([
            'catalog_company_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = $action->handle($request->user(), (int) $data['catalog_company_id']);

        return new AuthUserResource($user);
    }

    public function pricing(): JsonResponse
    {
        return response()->json([
            'price' => (float) config('rexmlm.secondary_company.price', 9.9),
            'currency' => config('rexmlm.secondary_company.currency', 'USD'),
        ]);
    }
}
