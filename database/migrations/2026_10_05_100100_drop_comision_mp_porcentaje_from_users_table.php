<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La comision de MP sale solo del Setting global `comision_mp_porcentaje`.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('comision_mp_porcentaje');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('comision_mp_porcentaje', 5, 2)->nullable()->after('retencion_iibb_porcentaje');
        });
    }
};
