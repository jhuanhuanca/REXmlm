@component('mail::message')
# Hola, {{ $user->name }}

Tu cuenta en {{ config('app.name') }} se creó correctamente. Ya puedes entrar a tu panel.

@component('mail::button', ['url' => $loginUrl])
Entrar a la plataforma
@endcomponent

Si no creaste esta cuenta, ignora este correo.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
