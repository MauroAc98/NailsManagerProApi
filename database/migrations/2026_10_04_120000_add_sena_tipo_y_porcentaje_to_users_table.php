<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Modo de la seña de la reserva online: monto fijo (sena_monto) o
            // porcentaje del total de la reserva (sena_porcentaje). String y no
            // enum nativo para no atar el esquema a un driver; los valores los
            // valida AuthController::updatePerfil.
            $table->string('sena_tipo', 12)->default('fijo')->after('sena_monto');
            $table->decimal('sena_porcentaje', 5, 2)->nullable()->after('sena_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sena_tipo', 'sena_porcentaje']);
        });
    }
};
