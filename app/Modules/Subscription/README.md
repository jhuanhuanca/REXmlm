# Módulo Subscription

**Fase:** 2.  
**Namespace:** `App\Modules\Subscription`

## Para qué existe

Catálogo de planes, ciclo de vida de la suscripción SaaS y **única** integración con Stripe (Cashier). Es el productor de los eventos de dinero que Commission escucha.

## Límites

**Sí**

- CRUD de planes (escritura solo Admin; lectura pública).
- Checkout, portal de cliente, estado `current`.
- Webhook Stripe verificado e idempotente.
- Mapear invoice → evento de dominio.

**No**

- Crear filas en `commissions`.
- Activar networks (emite evento; MLM escucha).
- PayPal en v1 (`BillingGateway` deja el hueco).

## Estructura

```
Subscription/
  Http/Controllers     PlanController, CheckoutController, WebhookController
  Http/Requests
  Http/Resources       PlanResource, CurrentSubscriptionResource
  Models               Plan  (+ modelos Cashier de Laravel)
  Services             StripeBillingGateway (impl. BillingGateway)
  Actions              StartCheckoutAction, HandleStripeEventAction
  Events               SubscriptionActivated
                       SubscriptionCancelled
                       SubscriptionPaymentSucceeded
                       SubscriptionPaymentRefunded
  Listeners            (pocos; este módulo produce más de lo que consume)
  Jobs                 ProcessStripeEventJob
  Policies             PlanPolicy
  routes/api.php
```

## Eventos de dinero (contrato)

`SubscriptionPaymentSucceeded`

- `userId` (quien pagó)
- `invoiceId` (idempotencia)
- `amount`, `currency`
- `planId`
- `referralCommissionPercent` (snapshot del plan)

Commission no consulta Stripe para saber el %.

## Invariantes

1. Webhook responde 2xx y procesa en cola `high`.
2. Event id de Stripe se guarda; duplicado = no-op.
3. Un pago de socio dispara el mismo evento; MLM decide si hay que promover.

## Tests

- Firma inválida → 400.
- Evento duplicado no duplica side-effects (usar fake de listeners).
- Plan inactivo no sale en catálogo público.
