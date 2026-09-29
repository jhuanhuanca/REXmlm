@props([
    'eyebrow' => '',
    'title',
    'actionUrl' => null,
    'actionLabel' => 'Continuar',
    'note' => null,
])
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#F4EFE4;-webkit-text-size-adjust:100%;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4EFE4;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    <tr>
                        <td style="background:#FFD452;border-radius:20px 20px 0 0;padding:22px 28px;">
                            <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#1C1914;letter-spacing:-0.03em;">
                                {{ config('app.name') }}
                            </p>
                            @if ($eyebrow)
                                <p style="margin:8px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:#1C1914;opacity:0.72;">
                                    {{ $eyebrow }}
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#FFFEF8;padding:28px;border-left:1px solid #E8E1D4;border-right:1px solid #E8E1D4;">
                            <h1 style="margin:0 0 14px;font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:1.25;color:#1C1914;font-weight:700;">
                                {{ $title }}
                            </h1>
                            <div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#3D3830;">
                                {{ $slot }}
                            </div>
                            @if ($actionUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 8px;">
                                    <tr>
                                        <td style="background:#1C1914;border-radius:999px;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#FFFEF8;text-decoration:none;">
                                                {{ $actionLabel }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                            @if ($note)
                                <p style="margin:18px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;color:#7A7368;">
                                    {{ $note }}
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#1C1914;border-radius:0 0 20px 20px;padding:16px 28px;">
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#FFD452;">
                                {{ config('app.name') }} · Panel de líderes
                            </p>
                            <p style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#C9C2B6;">
                                © {{ date('Y') }} {{ config('app.name') }}. Este correo es transaccional.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
