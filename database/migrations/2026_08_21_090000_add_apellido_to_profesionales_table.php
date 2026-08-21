<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profesionales', function (Blueprint $table) {
            // Nullable: las profesionales existentes solo tienen 'nombre'
            // cargado (a veces con nombre y apellido juntos ahí) — se
            // completa a mano en configuracion/profesionales, no hay
            // backfill automático posible.
            $table->string('apellido')->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('profesionales', function (Blueprint $table) {
            $table->dropColumn('apellido');
        });
    }
};
