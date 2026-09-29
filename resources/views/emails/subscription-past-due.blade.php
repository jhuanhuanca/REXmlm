<x-email.shell
    eyebrow="Pago"
    title="Hola, {{ $user->name }}"
    :action-url="config('rexmlm.frontend_url').'/app'"
    action-label="Regularizar pago"
>
    <p style="margin:0 0 12px;">
        No pudimos cobrar tu suscripción de líder. Hasta que el pago se regularice
        <strong>no puedes usar tienda, landing, equipo ni herramientas de líder</strong>.
    </p>
    <p style="margin:0;">
        Tu red y el histórico no se borran. En cuanto Paddle cobre el plan (o actualices la tarjeta), el acceso vuelve. Si cancelaste a propósito, sigues como socio de tu upline.
    </p>
</x-email.shell>
