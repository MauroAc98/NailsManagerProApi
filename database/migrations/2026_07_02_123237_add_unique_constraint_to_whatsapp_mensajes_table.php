<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->unique(['turno_id', 'tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropUnique(['turno_id', 'tipo']);
        });
    }
};
