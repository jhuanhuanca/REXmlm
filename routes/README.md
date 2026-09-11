# Rutas Laravel

Hoy el esqueleto trae `web.php` y `console.php`. El contrato objetivo:

| Archivo | Uso |
| --- | --- |
| `web.php` | Casi vacío. Health, quizás redirect. La API no vive aquí. |
| `console.php` | Schedule: expirar invitaciones, cierre mensual. |
| `api.php` (Fase 1) | Prefijo `v1`, health, include de módulos. |
| `channels.php` (Fase 4) | Broadcast Reverb. |

Las rutas de dominio **no** se amontonan en un api.php gigante. Cada módulo tiene `app/Modules/{X}/routes/api.php` cargado por su ServiceProvider con prefijos:

- Auth: `/api/v1/auth`
- MLM: `/api/v1`
- Admin: `/api/v1/admin`

Contrato: [`docs/05-api.md`](../../docs/05-api.md).
