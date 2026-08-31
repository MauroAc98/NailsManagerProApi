<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Opt-in explícito por salón para pedir seña en las
            // confirmaciones automáticas de WhatsApp. Se maneja como toggle
            // aparte (no se infiere de sena_monto) para poder pausarlo sin
            // borrar los datos bancarios — mismo criterio que
            // confirmacion_automatica / recordatorio_automatico.
            $table->boolean('whatsapp_pide_sena')->default(false)->after('sena_monto');

            // Datos de la cuenta para el pago de la seña. Todos nullable:
            // se validan como conjunto en AuthController::updatePerfil()
            // solo cuando whatsapp_pide_sena queda en true.
            $table->string('whatsapp_sena_titular', 120)->nullable()->after('whatsapp_pide_sena');
            $table->string('whatsapp_sena_entidad', 120)->nullable()->after('whatsapp_sena_titular');
            $table->string('whatsapp_sena_alias', 60)->nullable()->after('whatsapp_sena_entidad');
            $table->string('whatsapp_sena_cbu', 34)->nullable()->after('whatsapp_sena_alias');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_pide_sena',
                'whatsapp_sena_titular',
                'whatsapp_sena_entidad',
                'whatsapp_sena_alias',
                'whatsapp_sena_cbu',
            ]);
        });
    }
};
