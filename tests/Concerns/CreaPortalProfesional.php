<?php

namespace Tests\Concerns;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Helpers para los tests del portal de profesionales (cuenta + vinculos).
 *
 * Los tokens se crean con createToken() real (no Sanctum::actingAs): actingAs
 * devuelve un TransientToken que no ejerce el provider pineado ni las
 * abilities, que es justo lo que estos tests tienen que probar.
 */
trait CreaPortalProfesional
{
    /**
     * Negocio exento de suscripcion con su perfil titular (primera profesional)
     * y dos profesionales mas.
     *
     * @return array{user: User, titular: Profesional, ana: Profesional, bea: Profesional}
     */
    protected function crearNegocioPortal(array $userAttrs = []): array
    {
        $user = User::factory()->create(array_merge(['is_exempt' => true], $userAttrs));

        return [
            'user'    => $user,
            'titular' => $this->crearProfesionalPortal($user, 'Titular'),
            'ana'     => $this->crearProfesionalPortal($user, 'Ana'),
            'bea'     => $this->crearProfesionalPortal($user, 'Bea'),
        ];
    }

    protected function crearProfesionalPortal(User $user, string $nombre = 'Ana', bool $activo = true): Profesional
    {
        return Profesional::create([
            'user_id' => $user->id,
            'nombre'  => $nombre,
            'activo'  => $activo,
        ]);
    }

    protected function crearCuentaPortal(string $email = 'ana@x.com', string $password = 'password-seguro', array $attrs = []): ProfesionalCuenta
    {
        return ProfesionalCuenta::create(array_merge([
            'email'                 => $email,
            'password'              => Hash::make($password),
            'debe_cambiar_password' => false,
        ], $attrs));
    }

    protected function vincularPortal(ProfesionalCuenta $cuenta, Profesional $profesional, bool $activo = true, ?User $invitadoPor = null): ProfesionalVinculo
    {
        return ProfesionalVinculo::create([
            'cuenta_id'            => $cuenta->id,
            'profesional_id'       => $profesional->id,
            'activo'               => $activo,
            'invitado_por_user_id' => $invitadoPor?->id,
        ]);
    }

    /** A turno of $profesional (confirmado, 60 min, no client) unless $attrs say otherwise. */
    protected function crearTurnoPortal(Profesional $profesional, string $fechaHora = '2026-10-17 10:00:00', array $attrs = []): Turno
    {
        return Turno::create(array_merge([
            'user_id'                => $profesional->user_id,
            'profesional_id'         => $profesional->id,
            'fecha_hora'             => $fechaHora,
            'duracion_total_minutos' => 60,
            'estado'                 => 'confirmado',
            'origen'                 => 'app',
        ], $attrs));
    }

    protected function crearClientePortal(User $user, string $nombre = 'Maria', ?string $apellido = 'Lopez', string $telefono = '+5491155550001'): Cliente
    {
        return Cliente::create([
            'user_id'  => $user->id,
            'nombre'   => $nombre,
            'apellido' => $apellido,
            'telefono' => $telefono,
        ]);
    }

    protected function crearServicioPortal(User $user, string $nombre = 'Capping', array $attrs = []): Servicio
    {
        return Servicio::create(array_merge([
            'user_id'          => $user->id,
            'nombre'           => $nombre,
            'duracion_minutos' => 30,
            'precio'           => 10000,
            'activo'           => true,
        ], $attrs));
    }

    /**
     * @param  list<string>  $abilities
     */
    protected function tokenPara(ProfesionalCuenta $cuenta, array $abilities = ['pro:acceso']): string
    {
        return $cuenta->createToken('pro-portal', $abilities)->plainTextToken;
    }
}
