<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EnviarMensajeConfirmacionRequiereEnvioManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
    }

    public function test_no_envia_confirmacion_cuando_requiere_envio_manual(): void
    {
        Http::fake();

        // Sin teléfono cargado -> whatsapp_requiere_envio_manual = true.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => null,
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::where('turno_id', $turno->id)->count(),
        );
    }

    public function test_no_envia_confirmacion_cuando_el_veredicto_de_salud_cacheado_esta_en_rojo(): void
    {
        Http::fake();
        Cache::forever(CloudApiService::CACHE_KEY_SALUD, ['quality_rating' => 'RED']);

        // Teléfono y dirección completos, locale por defecto -> el único
        // motivo del flag es el veredicto de salud cacheado en rojo.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => '3765000000',
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));

        Http::assertNothingSent();

        $this->assertSame(
            0,
            WhatsappMensaje::where('turno_id', $turno->id)->count(),
        );
    }

    public function test_registra_un_log_cuando_omite_el_envio_por_requerir_envio_manual(): void
    {
        Http::fake();

        // Sin teléfono cargado -> whatsapp_requiere_envio_manual = true.
        $user = User::factory()->create([
            'is_exempt' => true,
            'telefono' => null,
        ]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '+543765252395',
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => now()->addHours(2),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($mensaje, $contexto) => is_string($mensaje)
                && ($contexto['turno_id'] ?? null) === $turno->id
                && ($contexto['user_id'] ?? null) === $user->id);

        (new EnviarMensajeConfirmacion($turno->id))->handle(app(CloudApiService::class));
    }
}
