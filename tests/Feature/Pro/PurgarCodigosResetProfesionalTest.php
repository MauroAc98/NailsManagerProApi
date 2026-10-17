<?php

namespace Tests\Feature\Pro;

use App\Mail\ProfesionalResetCodeMail;
use App\Models\ProfesionalCuenta;
use App\Services\Portal\EmitirCodigoReset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PR-03: the daily prune of `profesional_cuenta_reset_codes`.
 *
 * The one thing that must not go wrong: a row is not only a code, it is also
 * the memory of the forgot cooldown. An exhausted code (5 attempts) makes the
 * next forgot wait ENFRIAMIENTO_TRAS_AGOTAR_SEGUNDOS (1 h) while the code
 * itself lives VIGENCIA_SEGUNDOS (30 min). Pruning by `expira_en` alone would
 * erase that memory in the 30 minutes in between and hand an attacker five
 * fresh guesses. So a row goes only when BOTH its expiry and its cooldown are
 * over.
 */
class PurgarCodigosResetProfesionalTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const COMANDO = 'profesionales:purgar-codigos-reset';

    private int $ahora;

    private string $zonaOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaOriginal = date_default_timezone_get();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->ahora = now()->timestamp;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zonaOriginal);
        parent::tearDown();
    }

    /** One reset-code row for a fresh account; times are epoch seconds relative to now. */
    private function codigo(int $expiraEn, int $ultimoEnvioEn, int $intentos = 0, ?ProfesionalCuenta $cuenta = null): int
    {
        $cuenta ??= $this->crearCuentaPortal('c'.DB::table('profesional_cuenta_reset_codes')->count().'-'.uniqid().'@x.com');

        return DB::table('profesional_cuenta_reset_codes')->insertGetId([
            'cuenta_id' => $cuenta->id,
            'code_hash' => 'hash-'.uniqid(),
            'expira_en' => $expiraEn,
            'ultimo_envio_en' => $ultimoEnvioEn,
            'intentos' => $intentos,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function existe(int $id): bool
    {
        return DB::table('profesional_cuenta_reset_codes')->where('id', $id)->exists();
    }

    /** @return list<int> */
    private function idsRestantes(): array
    {
        return DB::table('profesional_cuenta_reset_codes')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function espera(): int
    {
        return EmitirCodigoReset::ENFRIAMIENTO_TRAS_AGOTAR_SEGUNDOS;
    }

    // ── Which rows go ──────────────────────────────────────────────────────

    public function test_it_deletes_rows_whose_code_expired_and_whose_cooldown_is_over_and_keeps_the_live_ones(): void
    {
        $vencido = $this->codigo($this->ahora - 100, $this->ahora - $this->espera() - 100);
        $vencidoAgotado = $this->codigo($this->ahora - 100, $this->ahora - $this->espera() - 100, EmitirCodigoReset::MAX_INTENTOS);
        $vivo = $this->codigo($this->ahora + 1000, $this->ahora - 100);
        $vivoAgotado = $this->codigo($this->ahora + 1000, $this->ahora - 100, EmitirCodigoReset::MAX_INTENTOS);

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertFalse($this->existe($vencido));
        $this->assertFalse($this->existe($vencidoAgotado));
        $this->assertTrue($this->existe($vivo));
        $this->assertTrue($this->existe($vivoAgotado));
    }

    public function test_a_row_that_expired_only_a_moment_ago_is_kept_while_its_cooldown_is_still_running(): void
    {
        // Sent 31 minutes ago: the code (30 min) is expired, the 1 h cooldown of an exhausted code is not.
        $agotado = $this->codigo($this->ahora - 60, $this->ahora - 31 * 60, EmitirCodigoReset::MAX_INTENTOS);
        $noAgotado = $this->codigo($this->ahora - 60, $this->ahora - 31 * 60, 2);

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertTrue($this->existe($agotado), 'An exhausted code keeps the cooldown alive past its expiry');
        $this->assertTrue($this->existe($noAgotado));
    }

    public function test_the_expiry_boundary_is_inclusive_like_the_reset_check(): void
    {
        // The reset treats `expira_en > now` as valid, so `expira_en == now` is already dead.
        $enElLimite = $this->codigo($this->ahora, $this->ahora - $this->espera());
        $unSegundoAntes = $this->codigo($this->ahora + 1, $this->ahora - $this->espera());

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertFalse($this->existe($enElLimite));
        $this->assertTrue($this->existe($unSegundoAntes));
    }

    public function test_the_cooldown_boundary_is_inclusive_like_the_forgot_check(): void
    {
        // Forgot blocks while `now - ultimo_envio_en < espera`, so at exactly `espera` it is free.
        $enElLimite = $this->codigo($this->ahora - 1, $this->ahora - $this->espera());
        $unSegundoAntes = $this->codigo($this->ahora - 1, $this->ahora - $this->espera() + 1);

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertFalse($this->existe($enElLimite));
        $this->assertTrue($this->existe($unSegundoAntes));
    }

    public function test_the_wait_it_honours_is_the_longest_cooldown_the_forgot_flow_can_impose(): void
    {
        $this->assertGreaterThanOrEqual(EmitirCodigoReset::ENFRIAMIENTO_SEGUNDOS, $this->espera());
        $this->assertSame(60 * 60, $this->espera());
    }

    // ── The interaction with the forgot cooldown ──────────────────────────

    public function test_pruning_inside_the_gap_between_expiry_and_cooldown_does_not_give_back_the_attempts(): void
    {
        Mail::fake();
        $cuenta = $this->crearCuentaPortal('ana@x.com');
        $this->vincularPortal($cuenta, $this->crearNegocioPortal()['ana']);

        // An exhausted code sent at t0.
        $id = $this->codigo($this->ahora + EmitirCodigoReset::VIGENCIA_SEGUNDOS, $this->ahora, EmitirCodigoReset::MAX_INTENTOS, $cuenta);

        // 40 minutes later: the code is dead, the 1 h cooldown is not over.
        Carbon::setTestNow(Carbon::createFromTimestamp($this->ahora + 40 * 60));
        $this->artisan(self::COMANDO)->assertSuccessful();
        $this->assertTrue($this->existe($id));

        app(EmitirCodigoReset::class)->handle('ana@x.com');

        Mail::assertNothingSent();
        $fila = DB::table('profesional_cuenta_reset_codes')->where('id', $id)->first();
        $this->assertSame(EmitirCodigoReset::MAX_INTENTOS, (int) $fila->intentos, 'No fresh attempts');
        $this->assertSame($this->ahora, (int) $fila->ultimo_envio_en);
    }

    public function test_once_the_cooldown_is_over_a_pruned_exhausted_row_lets_forgot_issue_a_normal_new_code(): void
    {
        Mail::fake();
        $cuenta = $this->crearCuentaPortal('ana@x.com');
        $this->vincularPortal($cuenta, $this->crearNegocioPortal()['ana']);
        $id = $this->codigo($this->ahora + EmitirCodigoReset::VIGENCIA_SEGUNDOS, $this->ahora, EmitirCodigoReset::MAX_INTENTOS, $cuenta);

        Carbon::setTestNow(Carbon::createFromTimestamp($this->ahora + $this->espera() + 1));
        $this->artisan(self::COMANDO)->assertSuccessful();
        $this->assertFalse($this->existe($id));

        app(EmitirCodigoReset::class)->handle('ana@x.com');

        Mail::assertSent(ProfesionalResetCodeMail::class, 1);
        $this->assertSame(0, (int) DB::table('profesional_cuenta_reset_codes')->where('cuenta_id', $cuenta->id)->value('intentos'));
    }

    // ── Output, idempotence, scope ─────────────────────────────────────────

    public function test_it_reports_how_many_rows_it_removed(): void
    {
        $this->codigo($this->ahora - 1, $this->ahora - $this->espera());
        $this->codigo($this->ahora - 1, $this->ahora - $this->espera());
        $this->codigo($this->ahora - 1, $this->ahora - $this->espera());
        $this->codigo($this->ahora + 500, $this->ahora);

        $this->artisan(self::COMANDO)
            ->expectsOutput('Códigos de recuperación del portal purgados: 3')
            ->assertSuccessful();
    }

    public function test_it_is_idempotent_and_reports_zero_on_the_second_run_and_on_an_empty_table(): void
    {
        $this->artisan(self::COMANDO)
            ->expectsOutput('Códigos de recuperación del portal purgados: 0')
            ->assertSuccessful();

        $viejo = $this->codigo($this->ahora - 1, $this->ahora - $this->espera());
        $vivo = $this->codigo($this->ahora + 500, $this->ahora);

        $this->artisan(self::COMANDO)->expectsOutput('Códigos de recuperación del portal purgados: 1')->assertSuccessful();
        $this->artisan(self::COMANDO)->expectsOutput('Códigos de recuperación del portal purgados: 0')->assertSuccessful();

        $this->assertFalse($this->existe($viejo));
        $this->assertSame([$vivo], $this->idsRestantes());
    }

    public function test_it_only_touches_the_reset_codes_table(): void
    {
        $cuenta = $this->crearCuentaPortal('ana@x.com');
        $this->tokenPara($cuenta);
        $this->codigo($this->ahora - 1, $this->ahora - $this->espera(), 0, $cuenta);

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame(0, DB::table('profesional_cuenta_reset_codes')->count());
        $this->assertSame(1, ProfesionalCuenta::count(), 'The account survives its pruned code');
        $this->assertSame(1, $cuenta->tokens()->count());
    }

    public function test_it_removes_every_expired_row_even_when_there_are_more_than_one_batch(): void
    {
        $lote = 500;
        $total = $lote * 2 + 7;
        $viejas = [];
        $vivas = [];
        for ($i = 0; $i < $total; $i++) {
            $viejas[] = ['email' => "v{$i}@x.com", 'password' => 'x', 'activo' => true, 'debe_cambiar_password' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('profesional_cuentas')->insert($viejas);
        $cuentas = DB::table('profesional_cuentas')->orderBy('id')->pluck('id')->all();

        $filas = [];
        foreach ($cuentas as $i => $cuentaId) {
            // The last three accounts keep a live code.
            $esViva = $i >= $total - 3;
            $filas[] = [
                'cuenta_id' => $cuentaId,
                'code_hash' => 'h',
                'expira_en' => $esViva ? $this->ahora + 900 : $this->ahora - 5,
                'ultimo_envio_en' => $esViva ? $this->ahora - 10 : $this->ahora - $this->espera() - 5,
                'intentos' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($filas, 200) as $trozo) {
            DB::table('profesional_cuenta_reset_codes')->insert($trozo);
        }
        $this->assertSame($total, DB::table('profesional_cuenta_reset_codes')->count());

        $this->artisan(self::COMANDO)
            ->expectsOutput('Códigos de recuperación del portal purgados: '.($total - 3))
            ->assertSuccessful();

        $this->assertSame(3, DB::table('profesional_cuenta_reset_codes')->count());
        $this->assertSame(0, DB::table('profesional_cuenta_reset_codes')->where('expira_en', '<=', $this->ahora)->count());
    }

    public function test_a_code_replaced_by_a_new_forgot_while_the_prune_runs_is_not_deleted(): void
    {
        $id = $this->codigo($this->ahora - 1, $this->ahora - $this->espera());
        $cuentaId = (int) DB::table('profesional_cuenta_reset_codes')->where('id', $id)->value('cuenta_id');

        // Another request replaces the dead code with a live one right after the prune picked its candidates.
        $this->alPrimerSelectDe('profesional_cuenta_reset_codes', function () use ($id) {
            DB::table('profesional_cuenta_reset_codes')->where('id', $id)->update([
                'expira_en' => $this->ahora + EmitirCodigoReset::VIGENCIA_SEGUNDOS,
                'ultimo_envio_en' => $this->ahora,
                'intentos' => 0,
            ]);
        });

        $this->artisan(self::COMANDO)->expectsOutput('Códigos de recuperación del portal purgados: 0')->assertSuccessful();

        $this->assertTrue($this->existe($id), 'The prune must not delete a code that became live after it chose its candidates');
        $this->assertSame($cuentaId, (int) DB::table('profesional_cuenta_reset_codes')->where('id', $id)->value('cuenta_id'));
    }

    // ── app.timezone is not UTC ────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function zonasHorarias(): array
    {
        return [
            'UTC' => ['UTC'],
            'Buenos Aires (UTC-3)' => ['America/Argentina/Buenos_Aires'],
            'Kiritimati (UTC+14)' => ['Pacific/Kiritimati'],
        ];
    }

    #[DataProvider('zonasHorarias')]
    public function test_the_comparison_uses_epoch_seconds_whatever_the_application_timezone(string $zona): void
    {
        config(['app.timezone' => $zona]);
        date_default_timezone_set($zona);
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', $zona));
        $ahora = now()->timestamp;

        $muerto = $this->codigo($ahora - 1, $ahora - $this->espera());
        $porVencer = $this->codigo($ahora + 1, $ahora - $this->espera());
        $enfriando = $this->codigo($ahora - 1, $ahora - $this->espera() + 1);
        $vivo = $this->codigo($ahora + 1800, $ahora);

        $this->artisan(self::COMANDO)->expectsOutput('Códigos de recuperación del portal purgados: 1')->assertSuccessful();

        $this->assertSame([$porVencer, $enfriando, $vivo], $this->idsRestantes());
        $this->assertFalse($this->existe($muerto));
    }

    // ── Scheduler (PR-03) ──────────────────────────────────────────────────

    public function test_the_command_is_registered_and_scheduled_daily(): void
    {
        $this->assertArrayHasKey(self::COMANDO, Artisan::all());

        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, self::COMANDO));

        $this->assertCount(1, $eventos, 'Scheduled exactly once');
        $this->assertSame('0 0 * * *', $eventos->first()->expression);
    }
}
