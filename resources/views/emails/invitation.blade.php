@component('mail::message')
# Invitación a {{ config('app.name') }}

{{ $invitation->leader?->name ?? 'Un líder' }} te invita a unirte a su red.

@component('mail::button', ['url' => $registerUrl])
Aceptar invitación
@endcomponent

Este enlace caduca el {{ $invitation->expires_at?->format('d/m/Y H:i') }}.

Si no esperabas este correo, ignóralo.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
