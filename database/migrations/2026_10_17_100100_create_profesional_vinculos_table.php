<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesional_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->constrained('profesional_cuentas')->cascadeOnDelete();
            $table->foreignId('profesional_id')->constrained('profesionales')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->foreignId('invitado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Camino de acceso "vinculos de esta cuenta" (columna izquierda: cuenta_id).
            $table->unique(['cuenta_id', 'profesional_id']);
            // Una profesional tiene como maximo una cuenta; tambien sirve a la FK.
            $table->unique('profesional_id');
            // Postgres no indexa las FK por su cuenta (ver 2026_10_13_120000).
            $table->index('invitado_por_user_id');
        });
    }

    // Se baja antes que profesional_cuentas: al revertir ambas migraciones el
    // orden inverso ya lo garantiza el batch, pero el down() solo toca esta tabla.
    public function down(): void
    {
        Schema::dropIfExists('profesional_vinculos');
    }
};
