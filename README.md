# REXmlm — API Laravel

Backend del producto. Laravel 12, PHP 8.2, monolito modular.

No hay lógica de negocio todavía. Lee primero:

- [Arquitectura](../docs/02-arquitectura.md)
- [Módulos](app/Modules/README.md)
- [Modelo de datos](../docs/04-modelo-datos.md)
- [Convenciones](../docs/11-convenciones.md)

## Dónde está el código (cuando exista)

| Qué | Dónde |
| --- | --- |
| Dominio | `app/Modules/*` |
| Contratos / enums | `app/Shared` |
| `User` de framework | `app/Models/User.php` |
| Providers de arranque | `app/Providers` + `*ServiceProvider` por módulo |
| Migraciones | `database/migrations` (prefijo de módulo en el nombre) |
| Tests | `tests/Feature/Modules`, `tests/Unit/Modules` |

## Arranque local (Laragon)

1. Virtual host apuntando a `REXmlm/public`.
2. `.env` con MySQL de Laragon.
3. Redis cuando se usen colas/sesiones (Fase 1+).
4. `composer install` ya está hecho en este esqueleto.
5. Las SPAs corren aparte (`sasmlm`, `sasadmin`) contra esta API.

Paquetes de dominio (Sanctum, Spatie, Cashier, Horizon) **no** están instalados; van por fase.
