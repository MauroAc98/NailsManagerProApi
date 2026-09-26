<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas_web', function (Blueprint $table) {
            // Tramos con offsets y duraciones en minutos enteros (sin
            // fechas: no dependen de la zona horaria). null = reserva de un
            // solo tramo (legacy).
            $table->json('tramos')->nullable();
            $table->string('tramos_modo', 20)->nullable(); // paralelo | secuencia
        });
    }

    public function down(): void
    {
        Schema::table('reservas_web', function (Blueprint $table) {
            $table->dropColumn(['tramos', 'tramos_modo']);
        });
    }
};
