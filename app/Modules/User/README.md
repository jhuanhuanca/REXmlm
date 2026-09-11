# Módulo User

**Fase:** 1.  
**Namespace:** `App\Modules\User`

## Para qué existe

Dueño del perfil, del estado de cuenta (`active` / `suspended` / `closed`) y del **directorio** que otros módulos consultan sin tocar el Eloquent `User` a su antojo.

Spatie Permission se configura aquí (roles y permisos). El modelo `App\Models\User` permanece en `app/Models` por convención Laravel; este módulo lo usa y expone contratos.

## Límites

**Sí**

- Show/update perfil (`name`, preferencias).
- Settings por usuario (`settings` scope=user).
- Cambio de password autenticado.
- Contrato `UserDirectory` (`find`, `isLeader`, `markSuspended`).
- Seeders de roles: `platform_admin`, `leader`, `partner`.

**No**

- Árbol, invitaciones, `sponsor_user_id` (MLM escribe esos campos vía contrato o columnas que MLM documenta; ver nota abajo).
- Stripe customer id (Subscription).

## Nota sobre columnas MLM en `users`

`sponsor_user_id` y `current_network_id` viven en la tabla `users` para no hacer join eterno, pero **las escribe solo MLM** (Action de accept/promote). User no ofrece un endpoint “cambiar de sponsor”.

## Estructura

```
User/
  Http/Controllers     ProfileController
  Http/Requests        UpdateProfileRequest
  Http/Resources       UserResource, ProfileResource
  Models               (opcional Profile; User sigue en App\Models)
  Services             UserDirectory (implementación del contrato)
  Actions              UpdateProfileAction, SuspendUserAction (llamado por Admin)
  Policies             UserPolicy
  routes/api.php
```

## Endpoints

- `GET/PUT /api/v1/profile`
- `PUT /api/v1/profile/password`

Admin no pasa por aquí: usa `Admin` que llama `SuspendUserAction`.

## Contratos que publica (`Shared/Contracts`)

```
UserDirectory
  find(int $id): ?UserDto
  findByEmail(string $email): ?UserDto
  assignRole(int $id, string $role): void
```

DTOs en `Shared/ValueObjects` o `User/ValueObjects` si no se comparten.

## Tests

- Un user actualiza su perfil, no el de otro.
- Suspender impide login (junto con Auth).
