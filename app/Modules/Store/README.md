# Módulo Store

**Fase:** 3 (implementado).  
**Namespace:** `App\Modules\Store`

Tienda por usuario. Catálogo, órdenes pendientes/pagadas. No genera comisión SaaS.

## Endpoints

Públicos: `GET /api/v1/store/{slug}`, `GET /api/v1/store/{slug}/products/{productSlug}`, `POST /api/v1/store/{slug}/orders`.

Dueño: `GET/PUT /api/v1/my-store`, CRUD `/api/v1/products`, `GET /api/v1/my-store/orders`, `POST /api/v1/my-store/orders/{id}/pay`.

## Invariantes

- Producto no se mueve de store; slug único por tienda.
- `order_items` guardan snapshot de nombre y precio.
- Políticas: solo el dueño muta; público exige `is_active`. IDs ajenos → 404 (`Owned::find` + `ProductPolicy` / `OrderPolicy` / etc.).
- Ventas reales = órdenes `paid`, nunca `sum(products.price)`.
- El catálogo de **fabricantes** (otra empresa, SKU global, plan de compensación de marca) vive en [`serv_producmlm`](../../../../serv_producmlm/README.md). Este módulo no lo consume todavía.

Load test de vitrina: `php artisan store:prepare-load-test` y `k6 run loadtest/storefront.js` (ver `loadtest/` en la raíz del monorepo).
