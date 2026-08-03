<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            $table->unsignedInteger('orden')->default(0)->after('es_promo');
        });

        // Backfill: se asigna un 'orden' secuencial por usuario siguiendo el
        // orden alfabético que tenían hasta ahora (por 'nombre'), para que
        // el orden visible no salte para las cuentas existentes justo
        // después del deploy.
        DB::table('servicios')
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->each(function ($userId) {
                DB::table('servicios')
                    ->where('user_id', $userId)
                    ->orderBy('nombre')
                    ->pluck('id')
                    ->each(function ($servicioId, $index) {
                        DB::table('servicios')
                            ->where('id', $servicioId)
                            ->update(['orden' => $index]);
                    });
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            $table->dropColumn('orden');
        });
    }
};
