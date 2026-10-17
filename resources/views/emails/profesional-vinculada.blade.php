<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background-color: #F7F7F9; padding: 24px;">
    <div style="max-width: 420px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; text-align: center;">
        <h2 style="color: #333; margin-bottom: 8px;">Turnetto</h2>
        <p style="color: #666; font-size: 14px; margin-bottom: 4px;">
            Hola {{ $nombreProfesional }}.
        </p>
        <p style="color: #666; font-size: 14px; margin-bottom: 4px;">
            La persona titular de {{ $negocio }} te invitó a ver tu agenda en el portal de profesionales.
        </p>
        <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
            Ya tenés una cuenta en el portal: ingresá con tu email y tu contraseña de siempre y vas a ver este negocio en tu lista.
        </p>

        <p style="color: #666; font-size: 14px; margin-bottom: 16px;">
            Portal de profesionales: <a href="{{ $portalUrl }}" style="color: #4e6b4d;">{{ $portalUrl }}</a>
        </p>

        <p style="color: #999; font-size: 12px;">
            Si no esperabas este mensaje, podés ignorarlo.
        </p>
    </div>
</body>
</html>
