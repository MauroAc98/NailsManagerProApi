<?php

namespace Tests\Concerns;

use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
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

    /**
     * @param  list<string>  $abilities
     */
    protected function tokenPara(ProfesionalCuenta $cuenta, array $abilities = ['pro:acceso']): string
    {
        return $cuenta->createToken('pro-portal', $abilities)->plainTextToken;
    }
}
