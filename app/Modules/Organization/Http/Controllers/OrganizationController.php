<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Actions\EnsureCatalogConnection;
use App\Modules\Organization\Actions\RunConnectionSync;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Actions\UpsertOrganizationConnection;
use App\Modules\Organization\Http\Requests\StoreOrganizationConnectionRequest;
use App\Modules\Organization\Http\Requests\StoreOrganizationRequest;
use App\Modules\Organization\Http\Requests\UpdateOrganizationMetricsProfileRequest;
use App\Modules\Organization\Http\Resources\OrganizationConnectionResource;
use App\Modules\Organization\Http\Resources\OrganizationResource;
use App\Modules\Organization\Metrics\OrganizationMetricsProfile;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $organizations = Organization::query()
            ->withCount('users')
            ->with(['connections' => fn ($query) => $query
                ->where('scope', ConnectionScope::Organization)
                ->with(['sources', 'syncs' => fn ($syncs) => $syncs->latest()->limit(1)])])
            ->orderBy('name')
            ->get();

        return OrganizationResource::collection($organizations);
    }

    public function store(StoreOrganizationRequest $request, SyncUserOrganization $sync): OrganizationResource
    {
        $data = $request->validated();
        $organization = $sync->findOrCreate(
            isset($data['catalog_company_id']) ? (int) $data['catalog_company_id'] : null,
            (string) $data['name'],
        );

        return new OrganizationResource($organization->loadCount('users'));
    }

    public function show(int $id, EnsureCatalogConnection $ensureCatalog): OrganizationResource
    {
        $organization = Organization::query()->withCount('users')->findOrFail($id);
        $ensureCatalog->handle($organization, request()->user());
        $organization->load([
            'connections' => fn ($query) => $query
                ->where('scope', ConnectionScope::Organization)
                ->with(['sources', 'syncs' => fn ($syncs) => $syncs->latest()->limit(1)]),
        ]);

        return new OrganizationResource($organization);
    }

    public function updateMetricsProfile(UpdateOrganizationMetricsProfileRequest $request, int $id): OrganizationResource
    {
        $organization = Organization::query()->findOrFail($id);
        $profile = OrganizationMetricsProfile::from([
            ...OrganizationMetricsProfile::from($organization->metrics_profile)->toArray(),
            ...$request->validated(),
        ]);
        $organization->forceFill(['metrics_profile' => $profile->toArray()])->save();

        return new OrganizationResource($organization->fresh()->loadCount('users'));
    }

    public function storeConnection(
        StoreOrganizationConnectionRequest $request,
        int $id,
        UpsertOrganizationConnection $upsert,
        RunConnectionSync $runner,
    ): JsonResponse {
        $organization = Organization::query()->findOrFail($id);
        $data = $request->validated();
        $file = $request->file('file');
        unset($data['file']);

        $connection = $upsert->handle(
            $organization,
            $request->user(),
            $data,
            $file,
        );

        $shouldSync = in_array($connection->driver?->value, ['catalog', 'api', 'excel', 'other'], true);
        $sync = $shouldSync ? $runner->handle($connection, $request->user()) : null;

        return response()->json([
            'connection' => new OrganizationConnectionResource($connection->fresh(['sources', 'syncs'])),
            'sync' => $sync,
        ], 201);
    }

    public function syncConnection(int $id, int $connectionId, RunConnectionSync $runner): JsonResponse
    {
        $connection = OrganizationConnection::query()
            ->where('organization_id', $id)
            ->findOrFail($connectionId);

        $sync = $runner->handle($connection, request()->user());

        return response()->json([
            'connection' => new OrganizationConnectionResource($connection->fresh(['sources', 'syncs'])),
            'sync' => $sync,
        ]);
    }

    public function destroyConnection(int $id, int $connectionId): JsonResponse
    {
        $connection = OrganizationConnection::query()
            ->where('organization_id', $id)
            ->findOrFail($connectionId);
        $connection->delete();

        return response()->json(['message' => 'Conexión desactivada']);
    }
}
