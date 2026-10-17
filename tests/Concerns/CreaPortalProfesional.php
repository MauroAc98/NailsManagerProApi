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
    /** @var list<callable>|null terminating callbacks of the freshly booted app (see reiniciarTareasPostRespuesta) */
    private ?array $callbacksBase = null;

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

    /** Swaps the hash manager for one that counts make() and check() calls and delegates. */
    protected function contarHashes(): HashManager
    {
        $contador = new class($this->app) extends HashManager
        {
            public int $llamadas = 0;

            public int $comprobaciones = 0;

            public function make($value, array $options = [])
            {
                $this->llamadas++;

                return parent::make($value, $options);
            }

            public function check($value, $hashedValue, array $options = [])
            {
                $this->comprobaciones++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        $this->app->instance('hash', $contador);
        Hash::clearResolvedInstance('hash');

        return $contador;
    }

    /**
     * `dispatch(...)->afterResponse()` registers an app "terminating" callback,
     * and the test app (one instance for the whole test) never drops the ones
     * that already ran: every later request would run the earlier requests'
     * closures again. Production has one request per process, so this is purely
     * a test artifact. Call this right BEFORE each request: the first call
     * snapshots the callbacks of a freshly booted app, later calls restore it.
     */
    protected function reiniciarTareasPostRespuesta(): void
    {
        $propiedad = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $this->callbacksBase ??= $propiedad->getValue($this->app);
        $propiedad->setValue($this->app, $this->callbacksBase);
    }

    /**
     * Sends ONE request through the HTTP kernel and stops before `terminate()`:
     * the response exists but the after-response tasks have not run yet. The
     * caller asserts what has (not) happened by now and then calls
     * `$kernel->terminate($request, $response)` exactly once (a second call
     * would replay the tasks, see reiniciarTareasPostRespuesta).
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array{0: \Illuminate\Http\Request, 1: \Symfony\Component\HttpFoundation\Response, 2: \Illuminate\Contracts\Http\Kernel}
     */
    protected function manejarSinTerminar(string $metodo, string $ruta, array $cuerpo = [], ?string $token = null): array
    {
        // Sanctum's guard caches the first resolved user per app instance.
        $this->app['auth']->forgetGuards();
        $this->reiniciarTareasPostRespuesta();

        $servidor = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.9.9.9'];
        if ($token !== null) {
            $servidor['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $request = \Illuminate\Http\Request::create($ruta, $metodo, $cuerpo, [], [], $servidor);

        return [$request, $kernel->handle($request), $kernel];
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
