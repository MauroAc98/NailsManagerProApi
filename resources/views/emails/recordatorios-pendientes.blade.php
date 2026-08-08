<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background-color: #F7F7F9; padding: 24px;">
    <div style="max-width: 420px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; text-align: center;">
        <h2 style="color: #333; margin-bottom: 8px;">💅 NailsManagerPro</h2>
        <p style="color: #666; font-size: 14px; margin-bottom: 4px;">
            ¡Hola {{ $name }}!
        </p>
        <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
            Tenés {{ $cantidadTurnos }} turno(s) mañana para recordar.
        </p>

        <div style="background: #FDF0F4; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
            <a href="{{ $url }}" style="background-color: #C96B91; color: white; padding: 14px 28px; border-radius: 12px; text-decoration: none; display: inline-block; font-size: 15px; font-weight: 600;">
                Recordar turnos
            </a>
        </div>

        <p style="color: #999; font-size: 12px;">
            Tu WhatsApp automático no está disponible en este momento, así que te dejamos armados los mensajes para que los mandes vos.
        </p>
    </div>
</body>
</html>
