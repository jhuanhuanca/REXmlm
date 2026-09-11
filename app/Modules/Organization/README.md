# Módulo Organization

**Fase:** 1–4 del Centro de Cierre.  
**Namespace:** `App\Modules\Organization`

Empresa MLM (HGW, …) dentro de REXmlm. **No** sustituye `networks`: un líder sigue teniendo una red; esa red cuelga de una organización.

## Sí

- Tabla `organizations` ligada a `catalog_company_id` de `serv_producmlm`.
- Membresía 1:1 en `organization_users` + `users.organization_id`.
- Zona horaria del cierre: país del líder, default `America/La_Paz`.
- El cierre lo calcula cada líder **solo con su red**.
- Conector por empresa: API, Excel/CSV, catálogo y “otro”. El líder puede importar un archivo **de su red**.
- Normalización canónica (Fase 3): Member, Customer, Order, Volume, bono de empresa, rango importado, genealogía. Sirve para HGW, DXN, Face Global, Omnilife u otra org.
- Perfil de métricas (Fase 4): umbrales y derivación publicados en sasadmin. Sin publicar, no hay “calificaste”.

## No

- Un líder en varias empresas.
- Un admin que cierre todas las redes de una empresa.
- Que cada líder configure la API del backoffice (eso es de la organización en sasadmin).

