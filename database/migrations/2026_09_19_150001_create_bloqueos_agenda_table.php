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
        Schema::create('bloqueos_agenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nullable = bloqueo para todo el salon (todas las profesionales
            // de la cuenta); con valor = bloqueo de esa profesional puntual.
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales')->cascadeOnDelete();
            $table->date('fecha');
            // Ambas null = bloqueo de dia completo; ambas presentes = bloqueo
            // parcial (rango horario). El invariante "ambas o ninguna" no se
            // puede expresar en DDL portable (sqlite/pgsql) — se valida en
            // BloqueoAgendaController::store.
            $table->time('hora_desde')->nullable();
            $table->time('hora_hasta')->nullable();
            $table->string('motivo', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fecha']);
            $table->index(['profesional_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bloqueos_agenda');
    }
};
