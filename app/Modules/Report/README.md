# Módulo Report

**Fase:** 6 (metas por periodo + cierre en caliente).  
**Namespace:** `App\Modules\Report`

Cierre mensual **en vivo** (no snapshot persistido todavía). Periodo en la zona horaria del **país del líder** (default `America/La_Paz`). Solo datos de **su red**.

Especificación de producto (Fase 0 del Centro de Cierre): [`docs/cierre/00-closing-specification-v1.md`](../../../../docs/cierre/00-closing-specification-v1.md).

## Endpoints

- `GET /api/v1/reports/monthly-closing?period=YYYY-MM` — permiso `report.view`
- `PUT /api/v1/reports/monthly-closing/goals` — metas del periodo (y del siguiente), permiso `report.view`
- `GET /api/v1/reports/download?period=YYYY-MM` — CSV, permiso `report.generate`

## Métricas (Fase 4)

Dos planos, nunca un solo número:

- **Plano A:** `sales`, `commissions` SaaS, equipo, invitaciones (tienda y plataforma REXmlm).
- **Plano B:** `company_volume`, `qualification`, `rank_progress` (empresa MLM vía conector + perfil publicado).
- **Proxy de tienda:** `store_proxy` — ventas de tienda etiquetadas “no es PV”.

Calificación (`qualified` / `not_qualified`) solo si el admin **publicó** umbrales en `organizations.metrics_profile`. Sin volumen, el estado es `insufficient_data`, nunca un 0 fingido.

