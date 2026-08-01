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
        Schema::table('profesionales', function (Blueprint $table) {
            $table->string('historia_precios_layout_id')->nullable()->after('fondo_historia_path');
            $table->string('historia_precios_estilo_id')->nullable()->after('historia_precios_layout_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profesionales', function (Blueprint $table) {
            $table->dropColumn(['historia_precios_layout_id', 'historia_precios_estilo_id']);
        });
    }
};
