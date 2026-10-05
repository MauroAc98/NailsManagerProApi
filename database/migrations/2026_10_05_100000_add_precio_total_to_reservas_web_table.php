<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas_web', function (Blueprint $table) {
            // Snapshot del precio total (pesos enteros) al crear el hold: la
            // sena se calcula sobre este valor, asi lo que ve el cliente en el
            // Resumen es lo que se cobra aunque cambien los precios despues.
            // null = precio desconocido (servicios sin precio).
            $table->unsignedInteger('precio_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservas_web', function (Blueprint $table) {
            $table->dropColumn('precio_total');
        });
    }
};
