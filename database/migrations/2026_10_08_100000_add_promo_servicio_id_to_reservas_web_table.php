<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La promo de la que nace una reserva web multi-profesional: el hold la
     * guarda y, al confirmar, el grupo de turnos la hereda (turno_grupos.promo_servicio_id).
     * null = seleccion suelta de servicios o reserva sin grupo.
     *
     * La FK (nullOnDelete) se agrega solo fuera de SQLite: ahi Laravel reconstruye la
     * tabla para agregar una FK y se pierde el indice unico parcial
     * reservas_web_slot_vivo_unique (WHERE estado IN ...). Los tests corren en SQLite.
     */
    public function up(): void
    {
        Schema::table('reservas_web', function (Blueprint $table) {
            $table->unsignedBigInteger('promo_servicio_id')->nullable()->after('tramos_modo');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('reservas_web', function (Blueprint $table) {
                $table->foreign('promo_servicio_id')->references('id')->on('servicios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('reservas_web', function (Blueprint $table) {
                $table->dropForeign(['promo_servicio_id']);
            });
        }

        Schema::table('reservas_web', function (Blueprint $table) {
            $table->dropColumn('promo_servicio_id');
        });
    }
};
