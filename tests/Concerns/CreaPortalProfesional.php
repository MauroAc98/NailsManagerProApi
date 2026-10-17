<?php

namespace Tests\Concerns;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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

    protected function vincularPortal(ProfesionalCuenta $cuenta, Profesional $profesional, bool $activo = true, ?User $invitadoPor = null, bool $cuentaCreadaAqui = false): ProfesionalVinculo
    {
        return ProfesionalVinculo::create([
            'cuenta_id'            => $cuenta->id,
            'profesional_id'       => $profesional->id,
            'activo'               => $activo,
            'invitado_por_user_id' => $invitadoPor?->id,
            'cuenta_creada_aqui'   => $cuentaCreadaAqui,
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

    /** Swaps the hash manager for one that counts make() calls and delegates. */
    protected function contarHashes(): HashManager
    {
        $contador = new class($this->app) extends HashManager
        {
            public int $llamadas = 0;

            public function make($value, array $options = [])
            {
                $this->llamadas++;

                return parent::make($value, $options);
            }
        };
        $this->app->instance('hash', $contador);
        Hash::clearResolvedInstance('hash');

        return $contador;
    }

    /**
     * Runs $competidor once, right after the first SELECT on $tabla: the
     * moment another request could slip in between our lookup and our insert.
     * The row it writes lives outside our savepoint, like a committed row would.
     */
    protected function alPrimerSelectDe(string $tabla, \Closure $competidor): void
    {
        $hecho = false;
        DB::listen(function ($consulta) use (&$hecho, $tabla, $competidor) {
            if (! $hecho && str_starts_with($consulta->sql, 'select') && str_contains($consulta->sql, '"'.$tabla.'"')) {
                $hecho = true;
                $competidor();
            }
        });
    }

    /**
     * Replaces the mailer with one that records each mailable together with
     * the transaction depth at the moment of sending, and optionally fails.
     *
     * @return \stdClass{mails: list<object>, niveles: list<int>}
     */
    protected function mailerGrabador(bool $falla): \stdClass
    {
        $registro = new \stdClass;
        $registro->mails = [];
        $registro->niveles = [];

        Mail::shouldReceive('to')->andReturnUsing(fn () => new class($registro, $falla)
        {
            public function __construct(private \stdClass $registro, private bool $falla) {}

            public function send($mailable): void
            {
                $this->registro->mails[] = $mailable;
                $this->registro->niveles[] = DB::transactionLevel();
                if ($this->falla) {
                    throw new \RuntimeException('smtp caido');
                }
            }
        });

        return $registro;
    }
}
