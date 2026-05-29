<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('reserva_web_id')->nullable()->constrained('reservas_web')->nullOnDelete();
            $table->dateTime('fecha_hora');
            $table->smallInteger('duracion_total_minutos')->unsigned();
            $table->enum('estado', [
                'confirmado',
                'cancelado',
                'completado',
            ])->default('confirmado');
            $table->enum('origen', ['app', 'web']);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fecha_hora', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos');
    }
};