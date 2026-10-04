<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Retencion de Ingresos Brutos que MP le aplica a cada cobro del
            // negocio; 0 = no se suma al monto de la seña.
            $table->decimal('retencion_iibb_porcentaje', 5, 2)->default(0)->after('sena_monto');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retencion_iibb_porcentaje');
        });
    }
};
