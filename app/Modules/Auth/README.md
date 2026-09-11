# Módulo Auth

**Fase:** 1 (base), 2 (2FA).  
**Namespace:** `App\Modules\Auth`

## Para qué existe

Es la puerta de la API. Autentica, abre y cierra sesión Sanctum, aplica lockout y 2FA. No conoce redes, planes ni tiendas.

## Límites

**Sí**

- Login / logout / usuario autenticado.
- Registro *público de líder* (delega la creación de User+Network a Actions de User/MLM vía evento o a un Action coordinado en User; Auth solo valida credenciales y dispara el alta).
- Forgot / reset password.
- Challenge 2FA (TOTP).
- Contador de fallos y `locked_until`.
- Middleware `EnsureTwoFactorConfirmed` para líderes y admin.

**No**

- Asignar roles de negocio (eso es User + seeders / MLM).
- Perfil (nombre, avatar): User.
- Aceptar invitaciones: MLM.

## Estructura

```
Auth/
  Http/Controllers     Login, Register, TwoFactor, Password
  Http/Middleware      2FA, optionally EnsureNotLocked
  Http/Requests
  Http/Resources       AuthUserResource (mínimo: id, name, email, roles, 2fa flag)
  Services             LoginAttemptService, TwoFactorService
  Actions              RegisterLeaderAction (o vive en User; no duplicar)
  Events               LoginFailed, UserLocked
  Listeners
  Jobs                 SendResetPasswordMail (o Notification)
  Notifications
  Policies             (pocas; el propio user)
  routes/api.php
```

`RegisterLeaderAction` puede vivir en User. Auth Controller llama a ese Action. Auth no duplica la persistencia.

## Endpoints que registra

Ver tabla Auth en [`docs/05-api.md`](../../../../docs/05-api.md).

## Dependencias

- `App\Models\User` (excepción permitida: es el modelo de framework).
- Spatie roles: lectura para armar el resource.
- Contrato `CreatesLeader` si el alta se extrae de User.

## Eventos

| Publica | Escucha |
| --- | --- |
| `LoginFailed`, `UserLocked`, `UserLoggedIn` | — |

## Tests mínimos (Fase 1)

- Login ok / fail / lockout al 5º intento.
- CSRF en SPA.
- Reset password rota el token.
- Un partner no entra a rutas admin (junto con Admin).
