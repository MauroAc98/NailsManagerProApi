<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            // user_id unique + cascadeOnDelete: campo por campo desde
            // create_user_mp_credentials_table. A lo sumo una conexión por salón.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Clave de ruteo de webhook — SOLO fallback de último recurso, NO
            // unique: una WABA puede tener más de un número de salones distintos.
            $table->string('waba_id', 32)->index();
            // Target de envío + clave primaria de ruteo de webhook + guarda de
            // colisión cross-salon. NOT NULL y UNIQUE a propósito: un target de
            // envío nullable forzaría un null-check en cada sitio de envío del
            // cambio hermano. Todo-o-nada: si no se resuelve el número, no se
            // persiste nada.
            $table->string('phone_number_id', 32)->unique();
            // Solo dígitos, normalizado con preg_replace('/\D/', '') al escribir
            // (EmbeddedSignupService paso 2) — matchea la comparación del
            // resolver de webhook. 2da clave de ruteo.
            $table->string('display_phone_number', 32)->nullable()->index();
            $table->string('verified_name')->nullable();
            // 'encrypted' cast en el modelo — el token de tenant nunca en claro.
            $table->text('access_token');
            // Epoch Unix crudo (segundos), NO un `datetime`/`timestamp` de
            // Eloquent — misma razón que whatsapp_mensajes.status_event_at (ver
            // comentario en add_status_event_at_to_whatsapp_mensajes_table): el
            // cast datetime formatea/parsea con el timezone default de PHP
            // (America/Argentina/Buenos_Aires, no UTC) en escritura y lectura,
            // corriendo el instante ~3hs en el round-trip. Meta devuelve
            // expires_in en segundos, así que el epoch es la representación
            // natural. NULL = token no expira (señal de Meta: expires_in ausente
            // o === 0). No "arreglar" esto de vuelta a datetime.
            $table->unsignedBigInteger('token_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
