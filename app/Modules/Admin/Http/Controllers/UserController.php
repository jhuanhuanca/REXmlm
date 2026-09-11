<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Http\Requests\StoreUserRequest;
use App\Modules\Admin\Http\Requests\UpdateUserRequest;
use App\Modules\Admin\Http\Resources\AdminUserResource;
use App\Services\Catalog\CatalogCompanyNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->with(['roles', 'companyMemberships'])
            ->withCount('referrals');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $matchingIds = $this->companyIdsMatching($search);
            $query->where(function ($q) use ($search, $matchingIds) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('catalog_company_name', 'like', '%'.$search.'%')
                    ->orWhere('catalog_rank_name', 'like', '%'.$search.'%')
                    ->orWhereHas('companyMemberships', function ($memberships) use ($search, $matchingIds) {
                        $memberships->where('catalog_company_name', 'like', '%'.$search.'%');
                        if ($matchingIds !== []) {
                            $memberships->orWhereIn('catalog_company_id', $matchingIds);
                        }
                    });

                if ($matchingIds !== []) {
                    $q->orWhereIn('catalog_company_id', $matchingIds);
                }
            });
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')));
        }

        if ($request->input('catalog_company_id') === 'none') {
            $query->whereNull('catalog_company_id')->whereDoesntHave('companyMemberships');
        } elseif ($request->filled('catalog_company_id')) {
            $companyId = $request->integer('catalog_company_id');
            $query->where(function ($q) use ($companyId) {
                $q->where('catalog_company_id', $companyId)
                    ->orWhereHas('companyMemberships', fn ($memberships) => $memberships->where('catalog_company_id', $companyId));
            });
        }

        $paginator = $query->paginate(20);
        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (User $user) => (new AdminUserResource($user))->resolve(),
            ),
        );

        return response()->json($paginator);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = $data['role'];
        unset($data['role'], $data['password_confirmation']);

        $user = User::query()->create($data);
        $user->syncRoles([$role]);

        return response()->json(
            (new AdminUserResource($user->load(['roles', 'companyMemberships'])->loadCount('referrals')))->resolve(),
            201,
        );
    }

    public function show(int $id): JsonResponse
    {
        $user = User::query()
            ->with(['roles', 'store', 'landingPage', 'referrals', 'companyMemberships'])
            ->findOrFail($id);

        return response()->json((new AdminUserResource($user))->resolve());
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $data = $request->validated();

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
            unset($data['role']);
        }

        unset($data['password_confirmation']);

        $user->update($data);

        return response()->json(
            (new AdminUserResource($user->fresh(['roles', 'companyMemberships'])))->resolve(),
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'Usuario desactivado']);
    }

    /**
     * @return list<int>
     */
    private function companyIdsMatching(string $search): array
    {
        $needle = mb_strtolower($search);

        return collect(app(CatalogCompanyNames::class)->all())
            ->filter(fn (string $name) => str_contains(mb_strtolower($name), $needle))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
