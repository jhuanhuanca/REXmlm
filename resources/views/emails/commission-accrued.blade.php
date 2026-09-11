@component('mail::message')
# Nueva comisión

Se ha generado una comisión pendiente por **{{ number_format((float) $commission->amount, 2) }} {{ $commission->currency }}**.

Porcentaje aplicado: {{ $commission->percentage }}%.

Entra al dashboard para ver el detalle.

@component('mail::button', ['url' => config('rexmlm.frontend_url').'/app/commissions'])
Ver comisiones
@endcomponent

{{ config('app.name') }}
@endcomponent
