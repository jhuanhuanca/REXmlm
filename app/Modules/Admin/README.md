# Módulo Admin

**Fase:** 5 (HTTP implementado).  
**Namespace:** `App\Modules\Admin`

Sin tablas propias. Prefijo `/api/v1/admin`, middleware `role:admin`.

## Endpoints

- Usuarios: listado (search/rol), alta, ficha, update de `status`/`role`/password, soft delete
- Planes: CRUD. No se borra un plan con suscripciones
- Comisiones: listado, `pay` (pending/approved → paid), `cancel` (no si ya está paid)
- Catálogo: `ANY /admin/catalog/{path}` reenvía a serv_producmlm con `X-Service-Token`

El payout bancario sigue siendo manual: `pay` solo registra el estado.
