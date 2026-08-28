<?php

namespace App\Services;

use App\Actions\GuardEmbeddedSignup;
use App\Exceptions\PhoneNumberYaVinculadoException;
use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el intercambio de 3 llamadas de Meta Embedded Signup (Coexistence).
 *
 * NO extiende ni depende de CloudApiService (design §3): credencial distinta
 * (business token por-tenant vs. app token de envío), endpoints distintos,
 * semántica de error distinta. Mantenerlos separados es lo que hace que el
 * cambio hermano `whatsapp-numero-propio-por-salon` sea una parametrización
 * limpia y no una reescritura de un servicio ahora de doble propósito.
 *
 * Contrato de transacción y rollback (design §3):
 *   - Los pasos 1-3 (HTTP) corren ENTERAMENTE fuera de toda transacción DB —
 *     nada de eso es rollback-able igual.
 *   - El paso 4 (upsert) es el único paso transaccional y va ÚLTIMO.
 *   - Orden deliberado: subscribe (3) ANTES de persistir (4). Se elige la
 *     falla RUIDOSA (suscripción huérfana del lado de Meta, recuperable
 *     re-corriendo ES) sobre la SILENCIOSA (credenciales sin webhook, que
 *     leería `conectada` para siempre).
 *   - Recuperación de cualquier falla de paso 1-4: re-correr ES. Los pasos
 *     1-3 no dejan estado local; el paso 3 es idempotente del lado de Meta;
 *     el paso 4 es un upsert.
 */
final class EmbeddedSignupService
{
    /** Expiry conservador (1h) para la única rama de `expires_in` malformada. */
    private const EXPIRY_CONSERVADOR = 3600;

    public function __construct(private readonly GuardEmbeddedSignup $guard) {}

    /**
     * El `User` es un parámetro EXPLÍCITO, nunca leído del contexto de auth —
     * este es el seam UI-agnóstico real (design §7). La gate de Advanced
     * Access corre PRIMERO acá dentro, de modo que todo caller queda gateado.
     */
    public function conectar(User $user, string $code, string $wabaId, ?string $phoneNumberId): WhatsappConnection
    {
        $this->guard->verificar($user);

        $version = config('services.whatsapp_es.graph_version');
        $base = "https://graph.facebook.com/{$version}";

        $token = $this->intercambiarCodigo($base, $code);
        $numero = $this->resolverNumero($base, $wabaId, $token['access_token'], $phoneNumberId);
        $this->suscribirApp($base, $wabaId, $token['access_token']);

        return $this->persistir($user, $wabaId, $numero, $token);
    }

    /**
     * Paso 1 — GET /oauth/access_token. timeout(8), SIN retry: el code es una
     * credencial de un solo uso con TTL de 30s; reintentar quema wall clock
     * sobre un code probablemente ya consumido. Nada persistido en fallo.
     *
     * @return array{access_token: string, token_expires_at: int|null}
     */
    private function intercambiarCodigo(string $base, string $code): array
    {
        $response = Http::timeout(8)->get("{$base}/oauth/access_token", [
            'client_id' => config('services.whatsapp_es.app_id'),
            'client_secret' => config('services.whatsapp_es.app_secret'),
            'code' => $code,
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            abort(422, 'El código de Embedded Signup expiró o es inválido. Reintentá la conexión.');
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'token_expires_at' => $this->calcularVencimiento($response->json('expires_in')),
        ];
    }

    /**
     * Paso 2 — GET /{waba}/phone_numbers. timeout(5), retry(3, 200ms): GET
     * idempotente puro, la única falla transitoria que si no tiraría a la
     * basura un token válido. INCONDICIONAL, no un fallback: es la única
     * fuente de `display_phone_number` y `verified_name`.
     *
     * @return array<string, mixed>
     */
    private function resolverNumero(string $base, string $wabaId, string $accessToken, ?string $phoneNumberId): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(5)
                ->retry(3, 200)
                ->get("{$base}/{$wabaId}/phone_numbers");
        } catch (RequestException|ConnectionException $e) {
            Log::error('whatsapp.es.phone_numbers_inaccesible', ['waba_id' => $wabaId, 'error' => $e->getMessage()]);
            abort(502, 'No se pudo obtener el número de WhatsApp de la cuenta. Reintentá.');
        }

        return $this->seleccionarNumero($response->json('data') ?? [], $phoneNumberId);
    }

    /**
     * Regla de selección (design §3):
     *   - id provisto pero ausente de la lista → 422 (señal de mismatch /
     *     tampering, no un fallback silencioso)
     *   - no provisto + exactamente 1 → tomarlo (Coexistence realistamente
     *     siempre tiene 1)
     *   - no provisto + >1 (o 0) → 422 pidiendo selección explícita; no
     *     adivinar qué número quiso decir el salón
     *
     * @param  array<int, array<string, mixed>>  $numeros
     * @return array<string, mixed>
     */
    private function seleccionarNumero(array $numeros, ?string $phoneNumberId): array
    {
        if ($phoneNumberId !== null && $phoneNumberId !== '') {
            foreach ($numeros as $numero) {
                if (($numero['id'] ?? null) === $phoneNumberId) {
                    return $numero;
                }
            }

            abort(422, 'El número indicado no pertenece a esta cuenta de WhatsApp Business.');
        }

        if (count($numeros) === 1) {
            return $numeros[0];
        }

        abort(422, count($numeros) === 0
            ? 'No se pudo determinar el número de WhatsApp de la cuenta.'
            : 'La cuenta tiene más de un número; indicá cuál conectar.');
    }

    /**
     * Paso 3 — POST /{waba}/subscribed_apps. timeout(8), retry(2, 300ms):
     * re-suscribir una app ya suscripta es un no-op de éxito del lado de Meta,
     * así que el retry es seguro. Nada persistido en fallo.
     */
    private function suscribirApp(string $base, string $wabaId, string $accessToken): void
    {
        try {
            Http::withToken($accessToken)
                ->timeout(8)
                ->retry(2, 300)
                ->post("{$base}/{$wabaId}/subscribed_apps");
        } catch (RequestException|ConnectionException $e) {
            Log::error('whatsapp.es.subscribed_apps_fallo', ['waba_id' => $wabaId, 'error' => $e->getMessage()]);
            abort(502, 'No se pudo suscribir la aplicación a la cuenta de WhatsApp. Reintentá.');
        }
    }

    /**
     * Paso 4 — ÚNICO paso transaccional, y va último. updateOrCreate sobre
     * `user_id`: idempotente por construcción (este ES el path de reconexión,
     * no hay endpoint separado).
     *
     * @param  array<string, mixed>  $numero
     * @param  array{access_token: string, token_expires_at: int|null}  $token
     */
    private function persistir(User $user, string $wabaId, array $numero, array $token): WhatsappConnection
    {
        $atributos = [
            'waba_id' => $wabaId,
            'phone_number_id' => (string) ($numero['id'] ?? ''),
            'display_phone_number' => preg_replace('/\D/', '', (string) ($numero['display_phone_number'] ?? '')),
            'verified_name' => $numero['verified_name'] ?? null,
            'access_token' => $token['access_token'],
            'token_expires_at' => $token['token_expires_at'],
        ];

        try {
            return DB::transaction(fn () => WhatsappConnection::updateOrCreate(
                ['user_id' => $user->id],
                $atributos,
            ));
        } catch (QueryException $e) {
            // Sólo SQLSTATE 23000 que referencia `phone_number_id` se traduce a
            // la excepción tipada (design §Q7). Un deadlock o cualquier otra
            // constraint se relanza intacto → 500, nunca mal-reportado como 409.
            if (($e->errorInfo[0] ?? null) === '23000' && str_contains($e->getMessage(), 'phone_number_id')) {
                Log::warning('whatsapp.es.phone_number_id_ya_vinculado', [
                    'phone_number_id' => $atributos['phone_number_id'],
                    'waba_id' => $wabaId,
                ]);

                throw new PhoneNumberYaVinculadoException($atributos['phone_number_id']);
            }

            Log::error('whatsapp.es.persistencia_fallo', [
                'waba_id' => $wabaId,
                'phone_number_id' => $atributos['phone_number_id'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * `expires_in` (design §2, A2):
     *   - ausente o === 0 → NULL (token no expira — el target de la decisión 2)
     *   - entero positivo → now + expires_in
     *   - no-numérico o negativo → now + 3600 + warning `whatsapp.es.expires_in_invalido`
     *     (ÚNICA rama malformada; un valor ausente es la señal de no-expira,
     *     no un parse-miss)
     */
    private function calcularVencimiento(mixed $expiresIn): ?int
    {
        if ($expiresIn === null || $expiresIn === 0) {
            return null;
        }

        if (! is_numeric($expiresIn) || $expiresIn < 0) {
            Log::warning('whatsapp.es.expires_in_invalido', ['expires_in' => $expiresIn]);

            return now()->timestamp + self::EXPIRY_CONSERVADOR;
        }

        return now()->timestamp + (int) $expiresIn;
    }
}
