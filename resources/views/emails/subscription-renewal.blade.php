@component('mail::message')
# Hola, {{ $user->name }}

El próximo cobro de tu plan **{{ $subscription->plan?->name ?? 'REXmlm' }}** será de **US$ {{ number_format($amount, 2) }}** el {{ $chargeDate }}.

Paddle lo cargará a la tarjeta que dejaste al activar el plan (el primer mes a US$ 1 ya pasó). Si no quieres continuar, cancela antes de esa fecha en tu panel.

@component('mail::button', ['url' => config('rexmlm.frontend_url').'/app'])
Ir al panel
@endcomponent

Gracias,<br>
{{ config('app.name') }}
@endcomponent
