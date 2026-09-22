<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_sena', function (Blueprint $table) {
            // Link de checkout de Mercado Pago (Checkout Pro), guardado tal cual
            // lo devuelve la API al crear la preferencia. Permite reusar la misma
            // preferencia si la clienta reintenta /pago sin crear otra en MP.
            $table->text('init_point')->nullable()->after('mp_preference_id');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_sena', function (Blueprint $table) {
            $table->dropColumn('init_point');
        });
    }
};
