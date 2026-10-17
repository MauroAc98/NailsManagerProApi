<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background-color: #F7F7F9; padding: 24px;">
    <div style="max-width: 400px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; text-align: center;">
        <h2 style="color: #333; margin-bottom: 8px;">Turnetto</h2>
        <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
            Usá este código para restablecer tu contraseña del portal de profesionales. Vence en 30 minutos.
        </p>
        <div style="background: #DEE6DE; border-radius: 12px; padding: 16px; margin-bottom: 24px;">
            <span style="font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #4e6b4d;">
                {{ $codigo }}
            </span>
        </div>
        <p style="color: #666; font-size: 14px; margin-bottom: 16px;">
            Ingresalo en el portal de profesionales: <a href="{{ $portalUrl }}" style="color: #4e6b4d;">{{ $portalUrl }}</a>
        </p>
        <p style="color: #999; font-size: 12px;">
            Si no lo pediste, podés ignorar este email: tu contraseña sigue siendo la misma.
        </p>
    </div>
</body>
</html>
