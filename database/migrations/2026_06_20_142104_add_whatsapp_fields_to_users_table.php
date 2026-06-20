<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nombre único de la instancia en Evolution API (ej: "user_5")
            $table->string('evolution_instance_name')->nullable()->after('mensaje_whatsapp');

            // Estado de la conexión: 'desconectado' | 'conectando' | 'conectado'
            $table->string('whatsapp_estado')->default('desconectado')->after('evolution_instance_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['evolution_instance_name', 'whatsapp_estado']);
        });
    }
};