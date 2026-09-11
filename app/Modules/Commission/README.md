# Módulo Commission

**Fase:** 2.  
**Namespace:** `App\Modules\Commission`

## Para qué existe

Convierte un pago de suscripción en una comisión para el referidor, con estados y auditoría. No habla con Stripe. No recorre el árbol para pagar multinivel de producto: **un nivel**, el referidor directo de plataforma.

## Límites

**Sí**

- Accrual idempotente a partir de `SubscriptionPaymentSucceeded`.
- Estados: `pending` → `approved` → `paid`; `reversed`.
- Listado para el líder (solo las suyas).
- Acciones de admin (vía Admin module que llama Actions de aquí).

**No**

- Transferencia bancaria / Stripe Connect (v1: el admin marca `paid` a mano).
- Comisión por órdenes de tienda (el modelo `source_type` lo permite después).

## Estructura

```
Commission/
  Http/Controllers     CommissionController
  Http/Requests
  Http/Resources       CommissionResource
  Models               Commission
  Services             CommissionCalculator
  Actions              AccrueCommissionAction
                       ApproveCommissionAction
                       PayCommissionAction
                       ReverseCommissionAction
  Events               CommissionAccrued, CommissionPaid, CommissionReversed
  Listeners            AccrueOnSubscriptionPayment
                       ReverseOnSubscriptionRefund
  Jobs                 AccrueCommissionJob, NotifyReferrerJob
  Policies             CommissionPolicy
  routes/api.php
```

## Cálculo v1

```
amount = round(invoice_amount * (plan.referral_commission_percent / 100), 2)
```

Si el pagador no tiene referidor (`referrals.referrer_id` nulo, p. ej. líder que llegó orgánico), **no se crea comisión**. Silencioso y logueado.

## Invariantes

1. Unique `(source_type, source_id, referrer_id)`.
2. `paid` es inmutable; el ajuste es una fila `reversed` o una comisión negativa explícita (elegir una en implementación y testearla). Recomendación: status `reversed` sobre la misma fila si aún no se pagó; si ya se pagó, nueva fila de ajuste y activity log.
3. El % se guarda en la fila (el plan puede cambiar mañana).

## Tests

- Dos webhooks iguales → una comisión.
- Sin referidor → cero filas.
- Líder no puede `POST .../pay` (solo admin).
- Refund revierte pending.
