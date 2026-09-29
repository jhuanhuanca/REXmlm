<x-email.shell
    eyebrow="Invitación"
    title="Te invitan a {{ config('app.name') }}"
    :action-url="$registerUrl"
    action-label="Aceptar invitación"
    :note="'Este enlace caduca el '.($invitation->expires_at?->format('d/m/Y H:i') ?? '—').'. Si no esperabas este correo, ignóralo.'"
>
    <p style="margin:0 0 12px;">
        <strong>{{ $invitation->leader?->name ?? 'Un líder' }}</strong> te invita a unirte a su red en {{ config('app.name') }}.
    </p>
    <p style="margin:0;">
        Al aceptar entras como socio: herramientas para asesorar, seguimiento en equipo y, cuando quieras, tu propio plan de líder.
    </p>
</x-email.shell>
