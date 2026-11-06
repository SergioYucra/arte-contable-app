{{-- Correo de seguridad genérico (HTML). Todo el contenido se escapa con {{ }}: nunca usar {!! !!} aquí. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2328;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #d0d7de;border-radius:8px;">
        <tr>
            <td style="padding:24px 28px;">
                <p style="margin:0 0 4px;font-size:12px;color:#57606a;text-transform:uppercase;letter-spacing:.05em;">{{ config('app.name') }}</p>
                <h1 style="margin:0 0 16px;font-size:20px;color:#12463a;">{{ $heading }}</h1>

                @foreach ($lines as $line)
                    <p style="margin:0 0 12px;font-size:14px;line-height:1.5;">{{ $line }}</p>
                @endforeach

                @if (! empty($details))
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;font-size:13px;">
                        @foreach ($details as $label => $value)
                            <tr>
                                <td style="padding:2px 12px 2px 0;color:#57606a;white-space:nowrap;vertical-align:top;">{{ $label }}</td>
                                <td style="padding:2px 0;">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if (! empty($actionUrl) && ! empty($actionText))
                    <p style="margin:20px 0;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:10px 18px;background:#1f6f5c;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;">{{ $actionText }}</a>
                    </p>
                    <p style="margin:0 0 12px;font-size:12px;color:#57606a;word-break:break-all;">Si el botón no funciona, copia este enlace en tu navegador: {{ $actionUrl }}</p>
                @endif

                @if (! empty($footer))
                    <p style="margin:16px 0 0;font-size:12px;color:#57606a;line-height:1.5;">{{ $footer }}</p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
