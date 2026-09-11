# Módulo Landing

**Fase:** 3 (implementado).  
**Namespace:** `App\Modules\Landing`

Una landing por usuario. Contenido JSON de bloques, sin HTML libre.

## Endpoints

- Público: `GET /api/v1/landing/{slug}` (solo publicadas).
- Dueño: `GET/PUT /api/v1/my-landing`, `POST /api/v1/my-landing/toggle-publish`.

## Schema de `content`

```json
{
  "hero": { "title": "", "subtitle": "", "cta_label": "", "cta_href": "" },
  "blocks": [
    { "type": "text", "body": "" },
    { "type": "image", "path": "" },
    { "type": "store_cta" }
  ]
}
```

Tipos desconocidos → 422.
