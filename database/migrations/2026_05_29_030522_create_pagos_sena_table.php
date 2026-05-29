<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_sena', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_web_id')->constrained('reservas_web')->cascadeOnDelete();
            $table->string('mp_preference_id');
            $table->string('mp_payment_id')->nullable()->unique();
            $table->decimal('monto', 10, 2);
            $table->enum('estado', [
                'pendiente',
                'aprobado',
                'rechazado',
                'expirado',
            ])->default('pendiente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_sena');
    }
};