<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas_web', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nombre_completo', 200);
            $table->string('telefono', 30);
            $table->json('servicio_ids');
            $table->date('fecha');
            $table->time('slot_hora');
            $table->smallInteger('duracion_total_minutos')->unsigned();
            $table->enum('estado', [
                'pending_payment',
                'accepted',
                'rejected',
                'expired',
            ])->default('pending_payment');
            $table->timestamps();

            $table->index(['user_id', 'fecha', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_web');
    }
};