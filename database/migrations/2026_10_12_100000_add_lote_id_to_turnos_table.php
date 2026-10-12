<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            // null = turno suelto. Turnos independientes creados juntos
            // (POST /turnos/lote) comparten este UUID; no es un grupo/combo.
            $table->uuid('lote_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropIndex(['lote_id']);
            $table->dropColumn('lote_id');
        });
    }
};
