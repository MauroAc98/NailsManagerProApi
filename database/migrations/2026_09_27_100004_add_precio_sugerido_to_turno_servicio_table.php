<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turno_servicio', function (Blueprint $table) {
            // Precio prorrateado de un tramo de promo, para pre-llenar el
            // cobro. Pesos enteros; null en todo turno que no es tramo.
            $table->unsignedInteger('precio_sugerido')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('turno_servicio', function (Blueprint $table) {
            $table->dropColumn('precio_sugerido');
        });
    }
};
