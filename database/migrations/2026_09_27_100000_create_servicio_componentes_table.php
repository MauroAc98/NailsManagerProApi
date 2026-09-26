<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Componentes de una promo: cada uno es un servicio individual ejecutado
     * por una profesional fija, en un orden. Aditivo: nadie lo lee todavia.
     */
    public function up(): void
    {
        Schema::create('servicio_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();
            $table->foreignId('componente_servicio_id')->constrained('servicios')->restrictOnDelete();
            $table->foreignId('profesional_id')->constrained('profesionales')->restrictOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->timestamps();

            $table->unique(['servicio_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_componentes');
    }
};
