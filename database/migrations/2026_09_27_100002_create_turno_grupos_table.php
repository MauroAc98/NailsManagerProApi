<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrupa los Turnos de una misma reserva multi-profesional (promo con
     * componentes o servicios sueltos con distintas profesionales).
     */
    public function up(): void
    {
        Schema::create('turno_grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
            $table->foreignId('reserva_web_id')->nullable()->constrained('reservas_web')->nullOnDelete();
            $table->string('modo', 20); // paralelo | secuencia
            $table->decimal('precio_promo', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turno_grupos');
    }
};
