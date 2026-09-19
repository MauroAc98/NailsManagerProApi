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
        Schema::table('profesionales', function (Blueprint $table) {
            // Nullable a proposito: NULL = atiende todos los dias (comportamiento
            // actual preservado, sin backfill). Array de enteros 0-6 (convencion
            // Carbon: 0=domingo..6=sabado), normalizado (deduplicado y ordenado)
            // en ProfesionalController::store/update — ver Profesional::atiendeEl.
            $table->json('dias_atencion')->nullable()->after('historia_precios_nota');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profesionales', function (Blueprint $table) {
            $table->dropColumn(['dias_atencion']);
        });
    }
};
