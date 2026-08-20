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
            $table->json('respuesta_api')->nullable()->after('status');
            $table->unsignedSmallInteger('status_code')->nullable()->after('respuesta_api');
            $table->dropColumn(['intentos', 'ultimo_intento']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropColumn(['respuesta_api', 'status_code']);
            $table->unsignedTinyInteger('intentos')->default(1);
            $table->timestamp('ultimo_intento')->nullable();
        });
    }
};
