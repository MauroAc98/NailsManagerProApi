<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add-on "reserva online": solo lo setea el admin (no esta en
            // $fillable). Vive en users y no en subscriptions porque las
            // cuentas is_exempt no tienen fila de suscripcion.
            $table->boolean('reserva_online')->default(false)->after('is_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reserva_online');
        });
    }
};
