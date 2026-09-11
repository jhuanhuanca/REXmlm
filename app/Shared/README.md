# Shared

**Namespace:** `App\Shared`  
No es un módulo de negocio. Aquí solo entra código que **dos o más módulos** necesitan. Si duda, déjalo en el módulo dueño.

## Carpetas

| Carpeta | Contenido típico |
| --- | --- |
| `Contracts/` | `UserDirectory`, `BillingGateway`, `NetworkDirectory` |
| `Enums/` | `UserStatus`, `CommissionStatus`, `InvitationStatus`, `OrderStatus` |
| `ValueObjects/` | `Money`, `Period` (`2026-08`) |
| `Exceptions/` | `DomainException` y subtipos (`InvitationExpiredException`) |
| `Support/` | helpers sin estado (p. ej. slugify de negocio) |
| `Traits/` | `BelongsToNetwork` (global scope) — candidato fuerte |

## Trait `BelongsToNetwork`

Casi todos los modelos de sasmlm tienen `network_id`. Un trait + global scope evita olvidar el filtro. El admin usa `Model::withoutNetworkScope()` de forma explícita y ruidosa.

## Qué no va aquí

- Jobs de un solo dominio.
- Controllers.
- Models Eloquent.

## Tests

`tests/Unit/Shared` — p. ej. `Money` rounding, `Period` parsing.
