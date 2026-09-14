<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Par de coordenadas del salón, usado para el header LOCATION de
            // las plantillas de WhatsApp (mapa) y para el picker del perfil.
            // 10,7 = 3 dígitos enteros (±180) + ~1.1 cm de resolución.
            // Ambos nullable y validados como conjunto (AuthController::
            // updatePerfil) — o los dos están cargados, o ninguno.
            $table->decimal('latitud', 10, 7)->nullable()->after('direccion');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud']);
        });
    }
};
