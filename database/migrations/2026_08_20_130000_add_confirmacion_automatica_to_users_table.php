<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Default true: hoy la confirmación siempre se manda automática
            // si no hay otro impedimento — las cuentas existentes no pueden
            // cambiar de comportamiento por este deploy.
            $table->boolean('confirmacion_automatica')->default(true)->after('recordatorio_automatico');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('confirmacion_automatica');
        });
    }
};
