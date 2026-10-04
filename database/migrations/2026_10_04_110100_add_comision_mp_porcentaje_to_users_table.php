<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Comision de MP propia del negocio, tal cual la muestra su cuenta
            // (sin IVA). NULL = usa la comision global (Setting).
            $table->decimal('comision_mp_porcentaje', 5, 2)->nullable()->after('retencion_iibb_porcentaje');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('comision_mp_porcentaje');
        });
    }
};
