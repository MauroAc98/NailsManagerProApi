<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background-color: #F7F7F9; padding: 24px;">
    <div style="max-width: 420px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; text-align: center;">
        <h2 style="color: #333; margin-bottom: 8px;">📅 Turnetto</h2>
        <p style="color: #666; font-size: 14px; margin-bottom: 4px;">
            ¡Hola {{ $name }}! Tu cuenta fue creada exitosamente.
        </p>
        <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
            Usá estos datos para iniciar sesión por primera vez:
        </p>

        <div style="background: #DEE6DE; border-radius: 12px; padding: 20px; margin-bottom: 16px; text-align: left;">
            <p style="margin: 0 0 8px 0; font-size: 13px; color: #999;">Email</p>
            <p style="margin: 0 0 16px 0; font-size: 15px; color: #333; font-weight: 600;">{{ $email }}</p>
            <p style="margin: 0 0 8px 0; font-size: 13px; color: #999;">Contraseña provisoria</p>
            <p style="margin: 0; font-size: 20px; font-weight: 700; letter-spacing: 2px; color: #4e6b4d;">
                {{ $password }}
            </p>
        </div>

        <p style="color: #999; font-size: 12px;">
            Por seguridad, al iniciar sesión por primera vez se te va a pedir que elijas una contraseña nueva.
        </p>
    </div>
</body>
</html>