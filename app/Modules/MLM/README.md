# Módulo MLM

**Fase:** 2 (invitaciones + dashboard implementados).  
**Namespace:** `App\Modules\MLM`

## Endpoints vivos

- `GET /api/v1/invitations/{token}`
- `POST /api/v1/invitations` (encola `SendInvitationEmail` con el token en claro; en BD solo hay hash)
- `GET /api/v1/dashboard`
- `GET /api/v1/dashboard/team`
- `GET /api/v1/dashboard/commissions`

El socio se crea en `POST /api/v1/auth/register` con `invitation_token`. Closure table queda para una iteración siguiente; el equipo se lista por `referrals` nivel 1+.


## Para qué existe

Es el corazón de la red: **networks**, **invitaciones**, **referrals** y **closure table**. El dashboard de equipo lee de aquí. No cobra dinero.

## Límites

**Sí**

- Crear network al registrar un líder (`pending` → `active` cuando Subscription avisa).
- CRUD de invitaciones + accept público.
- Mantener `referrals` y `network_closures`.
- Promoción socio → líder (crea network nueva, marca referral `independent`).
- Consultas de árbol y conteos por profundidad.

**No**

- Calcular montos de comisión.
- CRUD de tienda o landing (solo entrega `network_id`).
- Hablar con Stripe.

## Estructura

```
MLM/
  Http/Controllers     InvitationController, TeamController, NetworkController
  Http/Requests
  Http/Resources       TeamMemberResource, InvitationResource, TreeResource
  Models               Network, Referral, NetworkClosure, Invitation
  Services             ClosureTableService, TeamQueryService
  Actions              CreateInvitationAction
                       AcceptInvitationAction
                       ActivateNetworkAction
                       PromotePartnerToLeaderAction
                       RebuildNetworkClosureAction
  Events               InvitationCreated, InvitationAccepted, PartnerPromoted
  Jobs                 SendInvitationMailJob, RebuildNetworkClosureJob
  Policies             InvitationPolicy, NetworkPolicy
  routes/api.php
```

## Invariantes

1. Un token de invitación se hashea; el mail lleva el valor en claro una vez.
2. Accept es transaccional: user + role + referral + closures + sponsor_user_id.
3. Nadie inserta en `network_closures` fuera de `ClosureTableService`.
4. Un partner pertenece a una sola network a la vez (`current_network_id`).
5. Promote no borra el historial de la red anterior.

## Eventos

| Publica | Escucha |
| --- | --- |
| `InvitationCreated`, `InvitationAccepted`, `PartnerPromoted`, `NetworkActivated` | `SubscriptionActivated` → `ActivateNetworkAction` |

## Endpoints

Tabla “Invitaciones y equipo” en [`docs/05-api.md`](../../../../docs/05-api.md).

## Tests críticos

- Accept crea las filas de closure (self + todos los ancestros).
- Team tree no filtra otra network (IDOR).
- Promote crea network nueva y deja referral `independent`.
- Invitación expirada / revocada falla.
- Dos accepts del mismo token: el segundo falla.
