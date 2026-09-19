@component('mail::message')
# Hola, {{ $user->name }}

No pudimos cobrar tu suscripción de líder. Hasta que el pago se regularice **no puedes usar tienda, landing, equipo ni herramientas de líder**.

Tu red y el histórico no se borran. En cuanto Paddle cobre el plan (o actualices la tarjeta), el acceso vuelve.

@component('mail::button', ['url' => config('rexmlm.frontend_url').'/app'])
Regularizar pago
@endcomponent

Si cancelaste a propósito, sigues como socio de tu upline.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
