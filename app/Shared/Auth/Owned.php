<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class Owned
{
    /**
     * Autoriza con policy. Si el modelo no existe o no es del actor, 404 (anti-enumeración).
     *
     * @template T of Model
     *
     * @param  T|null  $model
     * @return T
     */
    public static function find(string $ability, ?Model $model, ?User $actor = null): Model
    {
        $user = $actor ?? request()->user();

        if ($model === null || $user === null || $user->cannot($ability, $model)) {
            abort(404);
        }

        return $model;
    }
}
