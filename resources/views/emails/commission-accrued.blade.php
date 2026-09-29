<x-email.shell
    eyebrow="Comisión"
    title="Nueva comisión pendiente"
    :action-url="config('rexmlm.frontend_url').'/app/commissions'"
    action-label="Ver comisiones"
>
    <p style="margin:0 0 12px;">
        Se acreditó una comisión de
        <strong>{{ number_format((float) $commission->amount, 2) }} {{ $commission->currency }}</strong>
        ({{ rtrim(rtrim(number_format((float) $commission->percentage, 2), '0'), '.') }}%).
    </p>
    <p style="margin:0;">
        Es el bono de referido cuando alguien de tu red paga el plan de lista. Entra al panel para ver el detalle y solicitar el retiro.
    </p>
</x-email.shell>
