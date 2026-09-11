<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Actions\RunConnectionSync;
use App\Modules\Organization\Actions\UpsertOrganizationConnection;
use App\Modules\Organization\Http\Requests\ImportNetworkConnectionRequest;
use App\Modules\Organization\Http\Resources\OrganizationConnectionResource;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Http\JsonResponse;

class LeaderConnectionController extends Controller
{
    public function index(): JsonResponse
    {
        $user = request()->user();
        $user?->loadMissing(['organization', 'ownedNetwork']);
        $organization = $user?->organization;

        if (! $organization) {
            return response()->json([
                'organization' => null,
                'official' => [],
                'network_imports' => [],
            ]);
        }

        $official = OrganizationConnection::query()
            ->where('organization_id', $organization->id)
            ->where('scope', ConnectionScope::Organization)
            ->with(['sources', 'syncs' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('driver')
            ->get();

        $networkId = $user->ownedNetwork?->id ?? $user->current_network_id;
        $mine = OrganizationConnection::query()
            ->where('organization_id', $organization->id)
            ->where('scope', ConnectionScope::Network)
            ->where(function ($query) use ($user, $networkId) {
                $query->where('user_id', $user->id);
                if ($networkId) {
                    $query->orWhere('network_id', $networkId);
                }
            })
            ->with(['sources', 'syncs' => fn ($query) => $query->latest()->limit(1)])
            ->latest()
            ->get();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'official' => OrganizationConnectionResource::collection($official)->resolve(request()),
            'network_imports' => OrganizationConnectionResource::collection($mine)->resolve(request()),
        ]);
    }

    public function import(
        ImportNetworkConnectionRequest $request,
        UpsertOrganizationConnection $upsert,
        RunConnectionSync $runner,
    ): JsonResponse {
        $user = $request->user();
        $user->loadMissing(['organization', 'ownedNetwork']);
        $organization = $user->organization;
        if (! $organization) {
            return response()->json(['message' => 'Tu cuenta no tiene empresa. El admin debe vincularte a una organización.'], 422);
        }

        $connection = $upsert->handle($organization, $user, [
            'driver' => ConnectionDriver::Excel->value,
            'scope' => ConnectionScope::Network->value,
            'name' => $request->input('name') ?: 'Excel de mi red',
        ], $request->file('file'));

        $sync = $runner->handle($connection, $user);

        return response()->json([
            'connection' => new OrganizationConnectionResource($connection->fresh(['sources', 'syncs'])),
            'sync' => $sync,
        ], 201);
    }
}
