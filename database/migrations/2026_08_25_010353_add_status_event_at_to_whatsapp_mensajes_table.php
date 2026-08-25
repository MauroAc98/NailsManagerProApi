<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            // Epoch Unix (segundos) del evento de status según Meta (payload
            // status.timestamp) — no el timestamp de cuándo NOSOTROS
            // procesamos el webhook. Meta no garantiza el orden de entrega
            // de los webhooks de status (reintentos, colas paralelas);
            // guardar el reloj de Meta permite descartar un evento que
            // llega tarde pero es cronológicamente viejo (ej. un 'sent' que
            // llega después de que ya procesamos un 'read'), en vez de
            // dejar que el status retroceda en silencio.
            //
            // Entero Unix crudo, NO un `timestamp`/`datetime` de Eloquent:
            // el cast datetime formatea/parsea usando el timezone default
            // de PHP (seteado desde config('app.timezone'), acá
            // America/Argentina/Buenos_Aires) tanto al guardar como al
            // leer, sin forzar UTC en ninguno de los dos pasos — un Carbon
            // construido en UTC explícito (Carbon::createFromTimestamp)
            // termina relabeleado como si sus mismos dígitos de reloj
            // fueran ART al releerlo, corriendo el instante representado
            // ~3hs. Comparar timestamps enteros evita esa clase entera de
            // bug de zona horaria.
            $table->unsignedBigInteger('status_event_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropColumn('status_event_at');
        });
    }
};
