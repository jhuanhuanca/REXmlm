<x-email.shell
    eyebrow="Suscripción"
    title="Hola, {{ $user->name }}"
    :action-url="config('rexmlm.frontend_url').'/app'"
    action-label="Ir al panel"
>
    <p style="margin:0 0 12px;">
        El próximo cobro de tu plan <strong>{{ $subscription->plan?->name ?? 'REXmlm' }}</strong>
        será de <strong>US$ {{ number_format($amount, 2) }}</strong> el {{ $chargeDate }}.
    </p>
    <p style="margin:0;">
        Paddle lo cargará a la tarjeta que dejaste al activar el plan (el primer mes a US$ 1 ya pasó). Si no quieres continuar, cancela antes de esa fecha en tu panel.
    </p>
</x-email.shell>
