<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SponsorPreviewController extends Controller
{
    public function __invoke(int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null || ! $user->hasRole(config('rexmlm.roles.leader'))) {
            abort(404);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
        ]);
    }
}
