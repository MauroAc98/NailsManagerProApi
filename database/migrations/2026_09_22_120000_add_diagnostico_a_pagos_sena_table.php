<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_sena', function (Blueprint $table) {
            // Motivo puntual de un pago rechazado (ej. 'cc_rejected_insufficient_amount'),
            // tal cual lo informa MP — sin esto, diagnosticar un reclamo de
            // "pagué y no me confirmó" requiere ir a buscarlo a mano al dashboard de MP.
            $table->string('status_detail')->nullable()->after('estado');
            // Medio de pago usado (ej. 'visa' / 'credit_card', 'account_money' /
            // 'account_money'), para distinguir reclamos por tarjeta de reclamos
            // por dinero en cuenta sin tener que ir a MP.
            $table->string('payment_method_id')->nullable()->after('status_detail');
            $table->string('payment_type_id')->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_sena', function (Blueprint $table) {
            $table->dropColumn(['status_detail', 'payment_method_id', 'payment_type_id']);
        });
    }
};
