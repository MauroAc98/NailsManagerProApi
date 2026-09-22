<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_mp_credentials', function (Blueprint $table) {
            // Segmento opaco de la URL del webhook de este negocio
            // (/api/webhooks/mercadopago/{webhook_ruteo}). Fase 1: cada negocio
            // tiene su PROPIA cuenta de Mercado Pago (su propia app, su propia
            // clave de firma), asi que un unico secreto compartido no sirve para
            // verificar la firma de todos. En vez de eso, este valor identifica
            // a que negocio pertenece la notificacion ANTES de hacer nada con
            // ella; nunca se confia en el cuerpo de la notificacion tal cual
            // llega — siempre se vuelve a consultar el pago real a la API de MP
            // con el access_token de ESE negocio antes de confirmar nada.
            // Autogenerado en el modelo (UserMpCredential::creating), nunca a
            // mano, para que cargar credenciales manualmente no dependa de
            // acordarse de este paso.
            $table->string('webhook_ruteo', 64)->nullable()->unique()->after('mp_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_mp_credentials', function (Blueprint $table) {
            $table->dropColumn('webhook_ruteo');
        });
    }
};
