<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // El salon puede atender a dos clientas a la vez con distintas
            // profesionales. Apagado por default; solo tiene sentido con mas
            // de una profesional activa.
            $table->boolean('atiende_en_paralelo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('atiende_en_paralelo');
        });
    }
};
