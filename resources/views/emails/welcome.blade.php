<x-email.shell
    eyebrow="Bienvenida"
    title="Hola, {{ $user->name }}"
    :action-url="$loginUrl"
    action-label="Entrar a la plataforma"
    note="Si no creaste esta cuenta, ignora este correo."
>
    <p style="margin:0 0 12px;">
        Tu cuenta en <strong>{{ config('app.name') }}</strong> ya está lista. Entra al panel para ver tu red, tienda y herramientas.
    </p>
    <p style="margin:0;">
        Guarda este correo: desde aquí vuelves a tu espacio de trabajo cuando lo necesites.
    </p>
</x-email.shell>
