<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null = nunca abrió el panel de notificaciones — todos los
            // mensajes de hoy cuentan como no vistos hasta ese momento.
            $table->timestamp('notificaciones_vistas_at')->nullable()->after('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notificaciones_vistas_at');
        });
    }
};
