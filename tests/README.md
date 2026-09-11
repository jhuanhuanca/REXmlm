# Tests

Espejo de los módulos. No se mezclan tests de Store con Auth.

```
tests/
  Feature/Modules/{Auth,User,MLM,Store,Landing,Subscription,Commission,Report,Admin}/
  Feature/Shared/
  Unit/Modules/{...}/
  Unit/Shared/
```

- **Feature:** HTTP + DB (RefreshDatabase). Un caso de uso por clase de test.
- **Unit:** Actions/Services/ValueObjects sin HTTP.

Prioridad cuando haya código: IDOR, idempotencia de webhooks, accept invitation, accrue commission.

Ver [`docs/11-convenciones.md`](../../docs/11-convenciones.md).
